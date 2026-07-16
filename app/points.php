<?php

function bridge_point_balance(mysqli $conn, int $userId): int {
    $stmt = $conn->prepare("SELECT balance FROM point_wallets WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $balance = (int)($stmt->get_result()->fetch_assoc()['balance'] ?? 0);
    $stmt->close();
    return $balance;
}

function bridge_add_points(mysqli $conn, int $userId, int $amount, string $actionType, string $refKey, string $description): bool {
    if ($userId <= 0 || $amount === 0 || $refKey === '') return false;

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            "INSERT IGNORE INTO point_transactions (user_id, amount, action_type, ref_key, description)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->bind_param("iisss", $userId, $amount, $actionType, $refKey, $description);
        $stmt->execute();
        $inserted = $stmt->affected_rows === 1;
        $stmt->close();

        if ($inserted) {
            $stmt = $conn->prepare(
                "INSERT INTO point_wallets (user_id, balance) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE balance = balance + VALUES(balance)"
            );
            $stmt->bind_param("ii", $userId, $amount);
            $stmt->execute();
            $stmt->close();
        }

        $conn->commit();
        return $inserted;
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

function bridge_admin_adjust_points(mysqli $conn, int $userId, int $amount, string $refKey, string $description): array {
    if ($userId <= 0 || $amount === 0 || $refKey === '') {
        return ['ok' => false, 'message' => '포인트 조정 정보를 확인해주세요.'];
    }

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("INSERT IGNORE INTO point_wallets (user_id, balance) VALUES (?, 0)");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("SELECT balance FROM point_wallets WHERE user_id = ? FOR UPDATE");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $balance = (int)($stmt->get_result()->fetch_assoc()['balance'] ?? 0);
        $stmt->close();
        if ($balance + $amount < 0) {
            $conn->rollback();
            return ['ok' => false, 'message' => '보유 포인트보다 많이 차감할 수 없어요.'];
        }

        $actionType = 'admin_adjust';
        $stmt = $conn->prepare(
            "INSERT INTO point_transactions (user_id, amount, action_type, ref_key, description)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->bind_param("iisss", $userId, $amount, $actionType, $refKey, $description);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("UPDATE point_wallets SET balance = balance + ? WHERE user_id = ?");
        $stmt->bind_param("ii", $amount, $userId);
        $stmt->execute();
        $stmt->close();
        $conn->commit();
        return ['ok' => true, 'balance' => $balance + $amount, 'message' => '포인트를 조정했어요.'];
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

function bridge_daily_visit_points(mysqli $conn, int $userId): void {
    bridge_add_points($conn, $userId, 2, 'daily_visit', date('Y-m-d'), '오늘의 첫 방문');
}

function bridge_spin_roulette(mysqli $conn, int $userId): array {
    $today = date('Y-m-d');
    $rewards = [0, 5, 5, 10, 10, 20, 50];
    $reward = $rewards[random_int(0, count($rewards) - 1)];

    $stmt = $conn->prepare("INSERT IGNORE INTO roulette_spins (user_id, spin_date, reward) VALUES (?, ?, ?)");
    $stmt->bind_param("isi", $userId, $today, $reward);
    $stmt->execute();
    $created = $stmt->affected_rows === 1;
    $stmt->close();

    if (!$created) {
        $stmt = $conn->prepare("SELECT reward FROM roulette_spins WHERE user_id = ? AND spin_date = ?");
        $stmt->bind_param("is", $userId, $today);
        $stmt->execute();
        $savedReward = (int)($stmt->get_result()->fetch_assoc()['reward'] ?? 0);
        $stmt->close();
        return ['ok' => false, 'reward' => $savedReward, 'message' => '오늘은 이미 룰렛에 참여했어요.'];
    }

    if ($reward > 0) {
        bridge_add_points($conn, $userId, $reward, 'roulette', $today, '오늘의 포인트 룰렛');
    }
    return ['ok' => true, 'reward' => $reward, 'message' => $reward > 0 ? $reward . 'P에 당첨됐어요!' : '아쉽지만 내일 다시 도전해보세요.'];
}

function bridge_point_badges(): array {
    return [
        'connector' => ['name' => '연결하는 사람', 'description' => '서로 다른 이야기를 잇는 첫 배지', 'cost' => 30, 'label' => 'CONNECTOR'],
        'storyteller' => ['name' => '이야기 수집가', 'description' => '꾸준히 기록하는 블로거를 위한 배지', 'cost' => 60, 'label' => 'STORYTELLER'],
        'bridge_master' => ['name' => '브리지 마스터', 'description' => 'BRIDGE 206의 대표 활동 배지', 'cost' => 120, 'label' => 'BRIDGE MASTER'],
    ];
}

function bridge_buy_badge(mysqli $conn, int $userId, string $badgeCode): array {
    $badges = bridge_point_badges();
    if (!isset($badges[$badgeCode])) return ['ok' => false, 'message' => '존재하지 않는 배지예요.'];
    $badge = $badges[$badgeCode];

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT balance FROM point_wallets WHERE user_id = ? FOR UPDATE");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $balance = (int)($stmt->get_result()->fetch_assoc()['balance'] ?? 0);
        $stmt->close();
        if ($balance < $badge['cost']) {
            $conn->rollback();
            return ['ok' => false, 'message' => '포인트가 부족해요.'];
        }

        $stmt = $conn->prepare("INSERT IGNORE INTO user_point_badges (user_id, badge_code) VALUES (?, ?)");
        $stmt->bind_param("is", $userId, $badgeCode);
        $stmt->execute();
        $created = $stmt->affected_rows === 1;
        $stmt->close();
        if (!$created) {
            $conn->rollback();
            return ['ok' => false, 'message' => '이미 보유한 배지예요.'];
        }

        $amount = -$badge['cost'];
        $description = $badge['name'] . ' 배지 구매';
        $stmt = $conn->prepare("INSERT INTO point_transactions (user_id, amount, action_type, ref_key, description) VALUES (?, ?, 'buy_badge', ?, ?)");
        $stmt->bind_param("iiss", $userId, $amount, $badgeCode, $description);
        $stmt->execute();
        $stmt->close();
        $stmt = $conn->prepare("UPDATE point_wallets SET balance = balance - ? WHERE user_id = ?");
        $stmt->bind_param("ii", $badge['cost'], $userId);
        $stmt->execute();
        $stmt->close();
        $conn->commit();
        return ['ok' => true, 'message' => $badge['name'] . ' 배지를 구매했어요.'];
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

function bridge_equip_badge(mysqli $conn, int $userId, string $badgeCode): array {
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT id FROM user_point_badges WHERE user_id = ? AND badge_code = ?");
        $stmt->bind_param("is", $userId, $badgeCode);
        $stmt->execute();
        $owned = (bool)$stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$owned) {
            $conn->rollback();
            return ['ok' => false, 'message' => '먼저 배지를 구매해주세요.'];
        }
        $stmt = $conn->prepare("UPDATE user_point_badges SET is_equipped = (badge_code = ?) WHERE user_id = ?");
        $stmt->bind_param("si", $badgeCode, $userId);
        $stmt->execute();
        $stmt->close();
        $conn->commit();
        return ['ok' => true, 'message' => '대표 배지를 변경했어요.'];
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}
