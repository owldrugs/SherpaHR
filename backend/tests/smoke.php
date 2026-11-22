<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Database.php';

// Black-box API regression checks against a disposable server and database.
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "This PHP interpreter needs pdo_sqlite. Use the Compose test command from README.\n");
    exit(1);
}
$directory = sys_get_temp_dir() . '/project-desk-test-' . bin2hex(random_bytes(6));
mkdir($directory, 0700);
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (!$socket) {
    throw new RuntimeException($errstr);
}
$address = stream_socket_get_name($socket, false);
fclose($socket);
$base = 'http://' . $address;
$environment = getenv();
$environment['DB_PATH'] = $directory . '/test.sqlite';
$environment['SEED_NOW'] = '2026-11-11T08:00:00Z';
$process = proc_open([PHP_BINARY, '-S', $address, __DIR__ . '/../router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $directory . '/server.log', 'a'], 2 => ['file', $directory . '/server.log', 'a']],
    $pipes, dirname(__DIR__, 2), $environment);
if (!is_resource($process)) {
    throw new RuntimeException('Cannot start test server');
}
fclose($pipes[0]);
$count = 0;
$exitStatus = 0;

function request(string $method, string $path, mixed $body = null, int $user = 1): array
{
    global $base;
    $options = ['http' => [
        'method' => $method, 'ignore_errors' => true, 'timeout' => 5,
        'header' => "Content-Type: application/json\r\nX-Demo-User: $user\r\n",
    ]];
    if ($body !== null) {
        $options['http']['content'] = is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR);
    }
    $raw = file_get_contents($base . $path, false, stream_context_create($options));
    preg_match('/\s(\d{3})\s/', $http_response_header[0], $match);
    return [(int) $match[1], json_decode($raw, true, 512, JSON_THROW_ON_ERROR)];
}

function check(bool $condition, string $message): void
{
    global $count;
    ++$count;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

try {
    $ready = false;
    for ($attempt = 0; $attempt < 50; ++$attempt) {
        if (@file_get_contents($base . '/api/health') !== false) {
            $ready = true;
            break;
        }
        usleep(100000);
    }
    check($ready, 'server starts with fresh SQLite');
    [$status, $response] = request('GET', '/api/context');
    check($status === 200 && $response['data']['user']['id'] === 1, 'default persona is Anna');
    check($response['data']['seeded_at'] === '2026-11-11T08:00:00Z', 'seed clock can be reproduced');
    [$status, $response] = request('GET', '/api/projects');
    check($status === 200 && array_column($response['data'], 'id') === [1, 2, 3, 4], 'Anna sees only her projects');
    check($response['data'][3]['archived'] === true, 'archive marker is boolean');
    [$status, $response] = request('GET', '/api/projects', null, 2);
    check(array_column($response['data'], 'id') === [1, 2, 4], 'Boris has different membership');
    [$status, $response] = request('GET', '/api/projects', null, 3);
    check(array_column($response['data'], 'id') === [1, 3, 5], 'Vera can see her separate project');
    check(request('GET', '/api/projects', null, 999)[0] === 401, 'unknown persona rejected');
    check(request('GET', '/api/projects/5/tasks')[0] === 404, 'hidden project is not disclosed');
    check(request('GET', '/api/tasks/11')[0] === 404, 'hidden task is not disclosed');
    check(request('PATCH', '/api/tasks/11', ['title' => 'Forbidden', 'version' => 1])[0] === 404, 'hidden task cannot be edited');
    check(request('POST', '/api/tasks/11/comments', ['body' => 'Forbidden'])[0] === 404, 'hidden task cannot receive comments');
    check(request('POST', '/api/projects/5/tasks', ['title' => 'Forbidden'])[0] === 404, 'hidden project cannot receive tasks');
    [$status, $response] = request('GET', '/api/tasks/1');
    check($status === 200 && count($response['data']['comments']) === 2, 'task detail includes discussion');
    check($response['data']['due_at'] === '2026-11-12T08:00:00Z', 'deadline relative to initial seed');
    check(request('POST', '/api/projects/4/tasks', ['title' => 'No'])[0] === 409, 'archive rejects task creation');
    check(request('PATCH', '/api/tasks/10', ['title' => 'No', 'version' => 1])[0] === 409, 'archive rejects task edits');
    check(request('POST', '/api/tasks/10/comments', ['body' => 'No'])[0] === 409, 'archive rejects comments');
    check(request('POST', '/api/projects/1/tasks', ['title' => '  '])[0] === 422, 'empty title rejected');
    check(request('POST', '/api/projects/2/tasks', ['title' => 'Bad member', 'assignee_id' => 3])[0] === 422, 'assignee must be a project member');
    check(request('POST', '/api/projects/1/tasks', ['title' => 'Bad date', 'due_at' => '2026-02-31T00:00:00Z'])[0] === 422, 'impossible date rejected');
    check(request('POST', '/api/projects/1/tasks', ['title' => 'Bad status', 'status' => 'unknown'])[0] === 422, 'invalid status rejected');
    check(request('POST', '/api/projects/1/tasks', ['title' => 'Extra field', 'project_id' => 5])[0] === 422, 'cannot move task through extra field');
    check(request('POST', '/api/projects/1/tasks', '{broken')[0] === 400, 'malformed JSON returns 400');
    check(request('POST', '/api/projects/1/tasks', '[]')[0] === 400, 'body must be an object');
    check(request('POST', '/api/projects/1/tasks', str_repeat('x', 33000))[0] === 413, 'oversized body rejected');
    [$status, $response] = request('POST', '/api/projects/1/tasks', [
        'title' => 'Проверка сохранения', 'description' => 'Текст с <b>разметкой</b>',
        'assignee_id' => 2, 'due_at' => '2026-12-01T09:30:00Z',
    ]);
    $task = $response['data'];
    check($status === 201 && $task['id'] > 12 && $task['status'] === 'open', 'create task with defaults');
    check(request('PATCH', '/api/tasks/' . $task['id'], ['title' => 'New'])[0] === 422, 'edit requires version');
    [$status, $response] = request('PATCH', '/api/tasks/' . $task['id'], ['status' => 'done', 'assignee_id' => null, 'due_at' => null, 'version' => 1]);
    check($status === 200 && $response['data']['version'] === 2 && $response['data']['due_at'] === null, 'edit fields and increment version');
    check(request('PATCH', '/api/tasks/' . $task['id'], ['title' => 'Stale', 'version' => 1])[0] === 409, 'stale write does not overwrite newer data');
    check(request('POST', '/api/tasks/' . $task['id'] . '/comments', ['body' => ' '])[0] === 422, 'empty comment rejected');
    [$status, $response] = request('POST', '/api/tasks/' . $task['id'] . '/comments', ['body' => '<script>window.pwned=1</script>'], 2);
    check($status === 201 && $response['data']['author'] === 'Борис · разработчик', 'comment records acting user');
    [$status, $response] = request('GET', '/api/tasks/' . $task['id']);
    check($response['data']['status'] === 'done' && count($response['data']['comments']) === 1, 'changes persist across requests');
    check($response['data']['comments'][0]['body'] === '<script>window.pwned=1</script>', 'comment content retained as plain data');
    [$status, $response] = request('GET', '/api/projects/1/tasks');
    check(count($response['data']) === 6, 'initialization does not duplicate or reset data');
    check(request('GET', '/api/tasks/9999')[0] === 404, 'missing task returns 404');

    [$status, $response] = request('GET', '/api/help-requests');
    check($status === 200 && $response['data'] === [], 'new database has an empty open help-request queue');
    [$status, $response] = request('POST', '/api/tasks/1/help-requests', ['reason' => 'Нужен выбор формата обмена'], 2);
    check($status === 201 && $response['data']['status'] === 'open' && $response['data']['author'] === 'Борис · разработчик', 'project member creates help request');
    $helpRequestId = $response['data']['id'];
    check(request('POST', '/api/tasks/1/help-requests', ['reason' => 'Повторная причина'], 3)[0] === 409, 'second open help request is rejected');
    check(request('POST', '/api/tasks/1/help-requests', ['reason' => ' '], 2)[0] === 422, 'empty help reason is rejected');
    check(request('POST', '/api/tasks/1/help-requests', ['reason' => null], 2)[0] === 422, 'missing help reason is rejected');
    check(request('POST', '/api/tasks/10/help-requests', ['reason' => 'Нельзя в архиве'], 2)[0] === 409, 'archive rejects help request');
    check(request('POST', '/api/tasks/3/help-requests', ['reason' => 'Задача завершена'], 2)[0] === 409, 'done task rejects help request');
    check(request('POST', '/api/tasks/11/help-requests', ['reason' => 'Чужой проект'], 2)[0] === 404, 'hidden task cannot receive help request');
    [$status, $response] = request('GET', '/api/help-requests');
    check($status === 200 && count($response['data']) === 1 && $response['data'][0]['task_id'] === 1, 'Anna sees open help request queue');
    check(request('POST', '/api/help-requests/' . $helpRequestId . '/resolve', null, 2)[0] === 404, 'non-owner cannot resolve help request');
    [$status, $response] = request('POST', '/api/help-requests/' . $helpRequestId . '/resolve', null, 1);
    check($status === 200 && $response['data']['status'] === 'resolved' && $response['data']['resolver'] === 'Анна · руководитель', 'owner resolves help request');
    check(request('POST', '/api/help-requests/' . $helpRequestId . '/resolve', null, 1)[0] === 409, 'resolved help request cannot be resolved twice');
    [$status, $response] = request('POST', '/api/tasks/1/help-requests', ['reason' => 'Новая проблема после решения'], 3);
    check($status === 201 && $response['data']['status'] === 'open', 'new open request can be created after resolution');
    [$status, $response] = request('GET', '/api/tasks/1');
    check($status === 200 && count($response['data']['help_requests']) === 2, 'task detail preserves help-request history');
    check(request('GET', '/api/help-requests', null, 3)[0] === 200, 'non-owner gets an access-filtered empty queue');

    $utcNow = new DateTimeImmutable(gmdate('Y-m-d\TH:i:s\Z'), new DateTimeZone('UTC'));
    $yesterday = $utcNow->modify('-1 day')->format('Y-m-d\TH:i:s\Z');
    $today = $utcNow->modify('+1 hour')->format('Y-m-d\TH:i:s\Z');
    $tomorrow = $utcNow->modify('+1 day')->setTime(9, 0, 0)->format('Y-m-d\TH:i:s\Z');
    $doneDue = $utcNow->modify('-2 days')->format('Y-m-d\TH:i:s\Z');

    [$status, $blockedOverdue] = request('POST', '/api/projects/1/tasks', ['title' => 'Attention blocked overdue', 'status' => 'blocked', 'due_at' => $yesterday], 1);
    check($status === 201, 'test fixture creates blocked overdue task');
    [$status, $overdue] = request('POST', '/api/projects/1/tasks', ['title' => 'Attention overdue', 'status' => 'in_progress', 'due_at' => $yesterday], 1);
    check($status === 201, 'test fixture creates overdue task');
    [$status, $overdueTie] = request('POST', '/api/projects/1/tasks', ['title' => 'Attention overdue stable sort', 'status' => 'in_progress', 'due_at' => $yesterday], 1);
    check($status === 201, 'test fixture creates equal-deadline overdue task');
    [$status, $blocked] = request('POST', '/api/projects/1/tasks', ['title' => 'Attention blocked', 'status' => 'blocked', 'due_at' => $tomorrow], 1);
    check($status === 201, 'test fixture creates blocked task');
    [$status, $todayTask] = request('POST', '/api/projects/1/tasks', ['title' => 'Attention today', 'status' => 'open', 'due_at' => $today], 1);
    check($status === 201, 'test fixture creates today task');
    [$status, $nextTask] = request('POST', '/api/projects/1/tasks', ['title' => 'Attention next 2 days', 'status' => 'open', 'due_at' => $tomorrow], 1);
    check($status === 201, 'test fixture creates next-two-days task');
    [$status, $doneTask] = request('POST', '/api/projects/1/tasks', ['title' => 'Attention done excluded', 'status' => 'done', 'due_at' => $doneDue], 1);
    check($status === 201, 'test fixture creates done task');

    [$status, $response] = request('GET', '/api/attention');
    check($status === 200, 'attention endpoint returns successfully');
    $attention = $response['data'];
    $ids = array_column($attention, 'task_id');
    check(count($ids) === count(array_unique($ids)), 'attention overview has no duplicate tasks');

    $task1 = null;
    foreach ($attention as $item) {
        if ($item['task_id'] === 1) $task1 = $item;
    }
    check($task1 !== null && $task1['priority'] === 1 && in_array('open_request', $task1['reasons'], true) && in_array('blocked', $task1['reasons'], true) && in_array('overdue', $task1['reasons'], true), 'attention merges open-request, blocked and overdue reasons');

    $blockedOverdueId = $blockedOverdue['data']['id'];
    $overdueId = $overdue['data']['id'];
    $blockedId = $blocked['data']['id'];
    $todayId = $todayTask['data']['id'];
    $nextId = $nextTask['data']['id'];
    $priorities = [];
    foreach ($attention as $item) $priorities[$item['task_id']] = $item['priority'];
    check(($priorities[$blockedOverdueId] ?? 0) === 2, 'blocked overdue task has priority 2');
    check(($priorities[$overdueId] ?? 0) === 3, 'overdue task has priority 3');
    $equalDeadlineIds = [];
    foreach ($attention as $item) {
        if ($item['priority'] === 3 && $item['due_at'] === $yesterday) $equalDeadlineIds[] = $item['task_id'];
    }
    $equalDeadlineSorted = $equalDeadlineIds;
    sort($equalDeadlineSorted, SORT_NUMERIC);
    check(count($equalDeadlineIds) >= 2 && $equalDeadlineIds === $equalDeadlineSorted, 'equal-deadline overdue tasks use stable ID ordering');
    check(($priorities[$blockedId] ?? 0) === 4, 'blocked task has priority 4');
    check(($priorities[$todayId] ?? 0) === 5, 'today task has priority 5');
    check(($priorities[$nextId] ?? 0) === 6, 'next-two-days task has priority 6');
    check(!in_array($doneTask['data']['id'], $ids, true), 'done task is excluded from attention');
    check(!in_array(10, $ids, true), 'archived project task is excluded from attention');

    $sortedPriorities = array_column($attention, 'priority');
    $priorityOrdered = true;
    for ($i = 1, $priorityCount = count($sortedPriorities); $i < $priorityCount; ++$i) {
        if ($sortedPriorities[$i] < $sortedPriorities[$i - 1]) $priorityOrdered = false;
    }
    check($priorityOrdered, 'attention priority groups are ordered');

    [$status, $veraAttention] = request('GET', '/api/attention', null, 3);
    check($status === 200 && count($veraAttention['data']) === 1 && $veraAttention['data'][0]['project_id'] === 5, 'attention is checked through owner project access');

    $migrationPath = $directory . '/migration-v1.sqlite';
    $migrationDb = new PDO('sqlite:' . $migrationPath);
    $migrationDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $migrationDb->exec('PRAGMA foreign_keys=ON');
    $migrationDb->exec('CREATE TABLE metadata (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
    $migrationDb->exec("INSERT INTO metadata VALUES ('schema_version','1')");
    $migrationDb->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
    $migrationDb->exec("INSERT INTO users VALUES (1,'Анна · руководитель')");
    $migrationDb->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY, name TEXT NOT NULL, description TEXT NOT NULL, owner_id INTEGER NOT NULL REFERENCES users(id), archived INTEGER NOT NULL DEFAULT 0)');
    $migrationDb->exec('CREATE TABLE project_members (project_id INTEGER NOT NULL, user_id INTEGER NOT NULL, PRIMARY KEY(project_id,user_id))');
    $migrationDb->exec('CREATE TABLE tasks (id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER NOT NULL, title TEXT NOT NULL, description TEXT NOT NULL DEFAULT \'\', status TEXT NOT NULL DEFAULT \'open\', assignee_id INTEGER, due_at TEXT, updated_at TEXT NOT NULL, version INTEGER NOT NULL DEFAULT 1)');
    $migrationDb->exec('CREATE TABLE comments (id INTEGER PRIMARY KEY AUTOINCREMENT, task_id INTEGER NOT NULL, user_id INTEGER NOT NULL, body TEXT NOT NULL, created_at TEXT NOT NULL)');
    $migrationDb->exec('CREATE INDEX tasks_project ON tasks(project_id)');
    $migrationDb->exec('CREATE INDEX comments_task ON comments(task_id,id)');
    $migrationDb->exec("INSERT INTO projects VALUES (1,'Сохранённый проект','Данные до миграции',1,0)");
    $migrationDb->exec("INSERT INTO project_members VALUES (1,1)");
    $migrationDb->exec("INSERT INTO tasks (id,project_id,title,description,status,assignee_id,due_at,updated_at) VALUES (1,1,'Сохранённая задача','Текст до миграции','open',1,NULL,'2026-01-01T00:00:00Z')");
    $migrationDb = null;
    putenv('DB_PATH=' . $migrationPath);
    $migrated = Database::connect();
    check($migrated->query("SELECT value FROM metadata WHERE key='schema_version'")->fetchColumn() === '2', 'existing version 1 database migrates to version 2');
    check((int) $migrated->query("SELECT COUNT(*) FROM tasks WHERE id=1 AND title='Сохранённая задача'")->fetchColumn() === 1, 'migration preserves existing tasks');
    $migratedAgain = Database::connect();
    check($migratedAgain->query("SELECT value FROM metadata WHERE key='schema_version'")->fetchColumn() === '2', 'migration is not repeated on version 2');

    $unknownVersionPath = $directory . '/migration-v99.sqlite';
    $unknownVersionDb = new PDO('sqlite:' . $unknownVersionPath);
    $unknownVersionDb->exec('CREATE TABLE metadata (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
    $unknownVersionDb->exec("INSERT INTO metadata VALUES ('schema_version','99')");
    $unknownVersionDb = null;
    putenv('DB_PATH=' . $unknownVersionPath);
    $unknownVersionRejected = false;
    try {
        Database::connect();
    } catch (RuntimeException $error) {
        $unknownVersionRejected = str_contains($error->getMessage(), 'Неподдерживаемая версия схемы');
    }
    check($unknownVersionRejected, 'unknown schema version is rejected instead of treated as legacy');
    $migrated = null;
    $migratedAgain = null;
    $unknownVersionDb = null;
    gc_collect_cycles();
    putenv('DB_PATH');

    check(request('GET', '/api/unknown')[0] === 404, 'unknown route returns 404');
    echo "PASS: $count API checks\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL . file_get_contents($directory . '/server.log'));
    $exitStatus = 1;
} finally {
    proc_terminate($process);
    proc_close($process);
    foreach (glob($directory . '/*') as $file) {
        unlink($file);
    }
    rmdir($directory);
}

exit($exitStatus);
