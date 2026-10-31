<?php
header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/attendance_sync.php';
$attendanceLock = alpha_lock();

define('DATA_FILE', __DIR__ . '/attendance_data.json');
define('OWNER_PASSWORD', '9mm9mm9mm'); // Yahan apna password daal sakte hain

function load_data() { return alpha_current(); }

function save_data($data) {
    alpha_archive($data);
    alpha_atomic(DATA_FILE, $data);
}

function check_password($password) {
    return $password === OWNER_PASSWORD;
}

$action = $_GET['action'] ?? '';

if ($action === 'mark' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['name'], $input['id'], $input['date'], $input['time'], $input['location'], $input['timestamp'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid input']);
        exit;
    }
    $data = load_data();
    $data[] = [
        'name' => trim($input['name']),
        'id' => trim($input['id']),
        'date' => $input['date'],
        'time' => $input['time'],
        'location' => $input['location'],
        'timestamp' => (int)$input['timestamp'],
        '_sync_id' => bin2hex(random_bytes(32)) . '-1'
    ];
    save_data($data);
    $newId = $data[count($data)-1]['_sync_id'];
    flock($attendanceLock, LOCK_UN); fclose($attendanceLock);
    session_write_close();
    // Attendance is already durable; Sheets failure must not make the employee resubmit.
    try { alpha_attempt_sync($newId); } catch (Throwable $e) { error_log('ALPHA sync attempt failed'); }
    echo json_encode(['success' => true, 'message' => 'Attendance marked']);
    exit;
}

if ($action === 'get' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $data = load_data();
    usort($data, function($a, $b) {
        return $b['timestamp'] <=> $a['timestamp'];
    });
    $result = ['success' => true, 'data' => $data];
    if (!empty($_SESSION['owner_logged_in'])) $result['sync'] = alpha_sync_status();
    echo json_encode($result);
    exit;
}

if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!isset($input['password'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Password required']);
        exit;
    }
    if (check_password($input['password'])) {
        $_SESSION['owner_logged_in'] = true;
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Incorrect password']);
    }
    exit;
}

if ($action === 'sync' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_SESSION['owner_logged_in'])) {
        http_response_code(403); echo json_encode(['success'=>false,'error'=>'Owner login required']); exit;
    }
    load_data();
    flock($attendanceLock, LOCK_UN); fclose($attendanceLock);
    session_write_close();
    $status = alpha_attempt_sync();
    echo json_encode(['success'=>!$status['last_error'], 'sync'=>$status, 'error'=>$status['last_error']]);
    exit;
}

if (in_array($action, ['delete','clear'], true) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_SESSION['owner_logged_in'])) {
        http_response_code(403); echo json_encode(['success'=>false,'error'=>'Owner login required']); exit;
    }
    $data = load_data();
    $state = alpha_state();
    if ($action === 'delete') {
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $input['sync_id'] ?? '';
        if (!is_string($id) || !preg_match('/^[a-f0-9]{64}-[1-9][0-9]*$/', $id)) {
            http_response_code(400); echo json_encode(['success'=>false,'error'=>'Invalid record ID']); exit;
        }
        if (!in_array($id, array_column($data, '_sync_id'), true) && empty($state['deleted'][$id])) {
            http_response_code(404); echo json_encode(['success'=>false,'error'=>'Record not found']); exit;
        }
        $ids = [$id];
    } else {
        // Explicit Clear all data also clears historical backup rows, not only this browser view.
        $ids = array_unique(array_merge(array_keys($state['records']), array_keys($state['deleted'])));
    }
    alpha_delete($ids, $data);
    flock($attendanceLock, LOCK_UN); fclose($attendanceLock);
    session_write_close();
    // Attempt immediately; durable markers let cron retry if Google is unavailable.
    try { alpha_attempt_sync(); } catch (Throwable $e) { error_log('ALPHA Sheets deletion pending'); }
    $attendanceLock = alpha_lock(); $state = alpha_state();
    $complete = count(array_diff($ids, array_keys($state['deleted_sent']))) === 0;
    echo json_encode(['success'=>true,'sheets_synced'=>$complete,
        'message'=>$complete ? 'Deleted from website and Google Sheets.' : 'Deleted from website. Google Sheets deletion is pending sync.']);
    exit;
}

if ($action === 'logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    session_destroy();
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'Invalid endpoint']);
