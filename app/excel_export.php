<?php

function bridge206ExcelFilename(string $prefix): string {
    return $prefix . '_' . date('Ymd_His') . '.xls';
}

function bridge206ExcelDownloadHeaders(string $filename): void {
    header('Content-Type: application/vnd.ms-excel; charset=CP949');
    header('Content-Disposition: attachment; filename="' . $filename . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
    header('Content-Transfer-Encoding: binary');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
}

function bridge206ExcelOut(string $html): void {
    echo mb_convert_encoding($html, 'CP949', 'UTF-8');
}

function bridge206ExcelText($value): string {
    if ($value === null || $value === '') {
        return '-';
    }
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function bridge206ExcelDate($value): string {
    if ($value === null || $value === '' || $value === '0000-00-00 00:00:00') {
        return '기록 없음';
    }
    $time = strtotime((string)$value);
    if ($time === false) {
        return (string)$value;
    }
    return date('Y-m-d H:i', $time);
}

function bridge206ExcelStart(string $title, string $subtitle = ''): void {
    bridge206ExcelOut(
        "<!DOCTYPE html>\n<html>\n<head>\n<meta charset=\"CP949\">\n" .
        "<style>\n" .
        "body{font-family:'Malgun Gothic',Arial,sans-serif;color:#1f2933;}\n" .
        "h1{font-size:24px;margin:0 0 8px 0;color:#0f5132;}\n" .
        ".subtitle{font-size:13px;color:#59636e;margin:0 0 20px 0;}\n" .
        "h2{font-size:17px;margin:22px 0 10px 0;color:#184f90;}\n" .
        "table{border-collapse:collapse;margin-bottom:14px;}\n" .
        "th{background:#e8f3ee;color:#173b2f;font-weight:bold;text-align:center;}\n" .
        "td,th{border:1px solid #aeb7c2;padding:9px 12px;font-size:13px;vertical-align:middle;}\n" .
        ".num{text-align:right;mso-number-format:'0';}\n" .
        ".date{text-align:center;mso-number-format:'yyyy\\-mm\\-dd hh:mm';white-space:nowrap;}\n" .
        ".note{color:#59636e;}\n" .
        ".layout{border-collapse:collapse;margin-bottom:8px;}\n" .
        ".layout>tbody>tr>td{border:0;padding:0 24px 6px 0;vertical-align:top;}\n" .
        ".layout h2{margin-top:10px;}\n" .
        "</style>\n</head>\n<body>\n" .
        "<h1>" . bridge206ExcelText($title) . "</h1>\n" .
        "<p class=\"subtitle\">" . bridge206ExcelText($subtitle !== '' ? $subtitle : '다운로드 일시: ' . date('Y-m-d H:i:s')) . "</p>\n"
    );
}

function bridge206ExcelEnd(): void {
    bridge206ExcelOut("</body>\n</html>");
}

function bridge206ExcelTable(string $title, array $headers, array $rows, array $widths = []): void {
    bridge206ExcelOut("<h2>" . bridge206ExcelText($title) . "</h2>\n<table>\n");
    bridge206ExcelTableBody($headers, $rows, $widths);
    bridge206ExcelOut("</table>\n");
}

function bridge206ExcelTableBody(array $headers, array $rows, array $widths = []): void {
    if ($widths) {
        bridge206ExcelOut("<colgroup>");
        foreach ($widths as $width) {
            bridge206ExcelOut("<col style=\"width:" . (int)$width . "px\">");
        }
        bridge206ExcelOut("</colgroup>\n");
    }
    bridge206ExcelOut("<tr>");
    foreach ($headers as $header) {
        bridge206ExcelOut("<th>" . bridge206ExcelText($header) . "</th>");
    }
    bridge206ExcelOut("</tr>\n");

    foreach ($rows as $row) {
        bridge206ExcelOut("<tr>");
        foreach ($row as $cell) {
            $class = '';
            $value = $cell;
            if (is_array($cell)) {
                $value = $cell['value'] ?? '';
                $class = isset($cell['class']) ? ' class="' . htmlspecialchars((string)$cell['class'], ENT_QUOTES, 'UTF-8') . '"' : '';
            }
            bridge206ExcelOut("<td$class>" . bridge206ExcelText($value) . "</td>");
        }
        bridge206ExcelOut("</tr>\n");
    }
}

function bridge206ExcelTableGroup(array $tables): void {
    bridge206ExcelOut("<table class=\"layout\"><tr>");
    foreach ($tables as $table) {
        bridge206ExcelOut("<td><h2>" . bridge206ExcelText($table['title'] ?? '') . "</h2><table>");
        bridge206ExcelTableBody($table['headers'] ?? [], $table['rows'] ?? [], $table['widths'] ?? []);
        bridge206ExcelOut("</table></td>");
    }
    bridge206ExcelOut("</tr></table>\n");
}
