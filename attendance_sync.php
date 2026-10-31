<?php
// Server-only helpers. Archive is PHP-protected, including on non-Apache hosts.
function alpha_lock() {
    $h = fopen(__DIR__ . '/attendance_sync.lock', 'c');
    if (!$h || !flock($h, LOCK_EX)) throw new RuntimeException('Cannot lock attendance');
    return $h;
}
function alpha_atomic($path, $data, $protected = false) {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $bytes = ($protected ? "<?php exit; ?>\n" : '') . $json;
    $tmp = tempnam(dirname($path), '.alpha-');
    if ($tmp === false || file_put_contents($tmp, $bytes) !== strlen($bytes) || !rename($tmp, $path)) {
        if ($tmp && file_exists($tmp)) unlink($tmp);
        throw new RuntimeException('Cannot save attendance');
    }
}
function alpha_state() {
    $path = __DIR__ . '/attendance_archive.php';
    $state = file_exists($path)
        ? json_decode(substr(file_get_contents($path), strlen("<?php exit; ?>\n")), true, 512, JSON_THROW_ON_ERROR)
        : ['records'=>[], 'sent'=>[]];
    if (!is_array($state)) throw new RuntimeException('Invalid sync archive');
    $state += ['deleted'=>[], 'deleted_sent'=>[]];
    return $state;
}
function alpha_identify($rows) {
    $counts = [];
    foreach ($rows as &$r) {
        $values = [];
        foreach (['name','id','date','time','location'] as $key) $values[] = (string)($r[$key] ?? '');
        $hash = hash('sha256', json_encode($values));
        $counts[$hash] = ($counts[$hash] ?? 0) + 1;
        if (empty($r['_sync_id'])) $r['_sync_id'] = $hash . '-' . $counts[$hash];
    }
    unset($r);
    return $rows;
}
function alpha_merge(&$state, $rows) {
    foreach (alpha_identify($rows) as $r) {
        $key = $r['_sync_id'];
        if (empty($state['deleted'][$key]) && !isset($state['records'][$key])) $state['records'][$key] = $r;
    }
}
function alpha_archive($rows) {
    $state = alpha_state();
    alpha_merge($state, require __DIR__ . '/attendance_seed.php');
    alpha_merge($state, $rows);
    alpha_atomic(__DIR__ . '/attendance_archive.php', $state, true);
}
// Caller holds alpha_lock(). Persist legacy IDs before any row is removed.
function alpha_current() {
    $file = __DIR__ . '/attendance_data.json';
    $rows = file_exists($file) ? json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR) : [];
    if (!is_array($rows)) throw new RuntimeException('Invalid attendance data');
    $state = alpha_state();
    $identified = array_values(array_filter(alpha_identify($rows), function($r) use ($state) {
        return empty($state['deleted'][$r['_sync_id']]);
    }));
    if ($rows !== $identified || !file_exists($file)) alpha_atomic($file, $identified);
    alpha_archive($identified);
    return $identified;
}
// Commit deletion markers first. alpha_current() repairs local rows after an interrupted write.
function alpha_delete($ids, $rows) {
    $state = alpha_state();
    foreach ($ids as $id) {
        $state['deleted'][$id] = true;
        unset($state['records'][$id], $state['sent'][$id]);
    }
    alpha_atomic(__DIR__ . '/attendance_archive.php', $state, true);
    $kept = array_values(array_filter($rows, function($r) use ($state) {
        return empty($state['deleted'][$r['_sync_id']]);
    }));
    alpha_atomic(__DIR__ . '/attendance_data.json', $kept);
}
 function alpha_sync($priorityId = null) {
    // Google Sheets sync temporarily disabled.
    return;

    // Neeche existing code waise hi rehne dein.
    $config = require __DIR__ . '/attendance_sync_config.php';
    if (!preg_match('~^https://script.google.com/macros/s/[^/]+/exec$~', $config['url']) || strlen($config['token']) < 32 || strpos($config['token'], 'PASTE_') === 0)
        throw new RuntimeException('Configure Apps Script URL and token first');
    $lock = alpha_lock();
    alpha_current();
    $state = alpha_state();
    $pending = [];
    $records = $state['records'];
    if ($priorityId && isset($records[$priorityId])) $records = [$priorityId=>$records[$priorityId]] + $records;
    foreach ($records as $id => $record) {
        if (empty($state['sent'][$id])) $pending[] = ['uid'=>$id, 'record'=>$record];
        if (count($pending) >= 100) break;
    }
    $deletes = array_slice(array_keys(array_diff_key($state['deleted'], $state['deleted_sent'])), 0, 100);
    flock($lock, LOCK_UN); fclose($lock);
    if (!$pending && !$deletes) return;
    if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL extension required');
    $ch = curl_init($config['url']);
    curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>json_encode(['token'=>$config['token'],'rows'=>$pending, 'deletes'=>$deletes]), CURLOPT_HTTPHEADER=>['Content-Type: application/json'], CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>true, CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_TIMEOUT=>15, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS]);
    $response = curl_exec($ch); $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); $curlFailed = curl_errno($ch); curl_close($ch);
    if ($curlFailed) throw new RuntimeException('Hosting could not reach Google Apps Script (network/timeout). Retry or ask hosting support.');
    $result = json_decode($response ?: '', true);
    if ($status !== 200) throw new RuntimeException('Google Apps Script returned HTTP '.$status.'. Check deployment access and URL.');
    if (!is_array($result)) throw new RuntimeException('Apps Script did not return JSON. Deploy as Me with access Anyone and use the /exec URL.');
    if (($result['error'] ?? '') === 'Unauthorized') throw new RuntimeException('SYNC_TOKEN does not match Apps Script Script properties.');
    if (empty($result['success']) || !is_array($result['ack'] ?? null)) throw new RuntimeException('Apps Script could not write to the Sheet. Check SHEET_ID, Attendance tab, permissions and Executions log.');
    $requested = array_column($pending, 'uid');
    $lock = alpha_lock(); $state = alpha_state();
    foreach ($result['ack'] as $id) if (in_array($id, $requested, true) && empty($state['deleted'][$id])) $state['sent'][$id] = true;
    foreach (($result['deletedAck'] ?? []) as $id) if (in_array($id, $deletes, true)) $state['deleted_sent'][$id] = true;
    alpha_atomic(__DIR__ . '/attendance_archive.php', $state, true);
    flock($lock, LOCK_UN); fclose($lock);
    if (count(array_intersect($requested, $result['ack'])) !== count($requested))
        throw new RuntimeException('Google did not acknowledge all records; remaining records will retry.');
    if ($deletes && count(array_intersect($deletes, $result['deletedAck'] ?? [])) !== count($deletes))
        throw new RuntimeException('Deletion pending: update Apps Script deployment and retry');
}
// Caller holds alpha_lock(); only return non-sensitive diagnostic information.
function alpha_sync_status() {
    $state = alpha_state();
    return [
        'pending_records'=>count(array_diff_key($state['records'], $state['sent'])),
        'pending_deletions'=>count(array_diff_key($state['deleted'], $state['deleted_sent'])),
        'last_error'=>$state['last_error'] ?? null,
        'last_success'=>$state['last_success'] ?? null
    ];
}
function alpha_attempt_sync($priorityId = null) {
    $error = null;
    try { alpha_sync($priorityId); } catch (Throwable $e) { $error = $e->getMessage(); }
    $lock = alpha_lock();
    $state = alpha_state(); $state['last_error'] = $error;
    if (!$error) $state['last_success'] = gmdate('c');
    alpha_atomic(__DIR__ . '/attendance_archive.php', $state, true);
    $status = alpha_sync_status();
    flock($lock, LOCK_UN); fclose($lock);
    return $status;
}
if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    try {
        $status = alpha_attempt_sync();
        echo json_encode($status)."\n";
        exit($status['last_error'] ? 1 : 0);
    } catch (Throwable $e) { fwrite(STDERR, $e->getMessage()."\n"); exit(1); }
}
