<?php

function bridge_media_info(array $files, int $index): ?array
{
    if (!isset($files['name'][$index], $files['tmp_name'][$index], $files['error'][$index])) {
        return null;
    }
    if ($files['error'][$index] !== UPLOAD_ERR_OK || !is_uploaded_file($files['tmp_name'][$index])) {
        return null;
    }

    $ext = strtolower(pathinfo($files['name'][$index], PATHINFO_EXTENSION));
    $images = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $videos = ['mp4', 'webm', 'mov', 'm4v'];
    $size = (int)($files['size'][$index] ?? 0);

    if (in_array($ext, $images, true)) {
        $type = 'image';
        $token = 'img';
        $prefix = 'img_';
        $maxSize = 8 * 1024 * 1024;
    } elseif (in_array($ext, $videos, true)) {
        $type = 'video';
        $token = 'video';
        $prefix = 'vid_';
        $maxSize = 50 * 1024 * 1024;
    } else {
        return null;
    }

    if ($size <= 0 || $size > $maxSize) {
        return null;
    }

    $mime = (string)($files['type'][$index] ?? '');
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $detected = finfo_file($finfo, $files['tmp_name'][$index]);
            if ($detected) {
                $mime = $detected;
            }
            finfo_close($finfo);
        }
    }

    if ($type === 'image' && strpos($mime, 'image/') !== 0) {
        return null;
    }
    if ($type === 'video' && strpos($mime, 'video/') !== 0 && $ext !== 'mov') {
        return null;
    }

    return [
        'type' => $type,
        'token' => $token,
        'prefix' => $prefix,
        'ext' => $ext,
        'mime' => $mime,
        'size' => $size,
        'original' => basename($files['name'][$index]),
        'tmp_name' => $files['tmp_name'][$index],
    ];
}

function bridge_save_inline_media(mysqli $conn, int $postId, string $content, ?array $files, int $startOrder = 0): array
{
    if (!$files || empty($files['name'][0])) {
        return [$content, 0];
    }
    if (!preg_match_all('/\[\[(img|video):new(\d+)(?:\|\d+)?\]\]/', $content, $matches, PREG_SET_ORDER)) {
        return [$content, 0];
    }

    $uploadDir = __DIR__ . '/../uploads';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $seen = [];
    $order = $startOrder;
    $saved = 0;
    $newContent = $content;

    foreach ($matches as $match) {
        $tokenType = $match[1];
        $index = (int)$match[2];
        $key = $tokenType . ':' . $index;
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;

        $media = bridge_media_info($files, $index);
        if (!$media || $media['token'] !== $tokenType) {
            continue;
        }

        $stored = uniqid($media['prefix'], true) . '.' . $media['ext'];
        if (!move_uploaded_file($media['tmp_name'], $uploadDir . '/' . $stored)) {
            continue;
        }

        $original = $media['original'];
        $mediaType = $media['type'];
        $mimeType = $media['mime'];
        $fileSize = $media['size'];

        $stmt = $conn->prepare(
            "INSERT INTO post_images
               (post_id, original, stored, sort_order, media_type, mime_type, file_size)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param(
            "ississi",
            $postId,
            $original,
            $stored,
            $order,
            $mediaType,
            $mimeType,
            $fileSize
        );
        $stmt->execute();
        $mediaId = $conn->insert_id;
        $stmt->close();

        $newContent = preg_replace('/\[\[' . $tokenType . ':new' . $index . '\b/', '[[' . $tokenType . ':' . $mediaId, $newContent);
        $order++;
        $saved++;
    }

    return [$newContent, $saved];
}
