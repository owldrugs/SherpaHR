<?php
declare(strict_types=1);

final class ApiError extends RuntimeException {}

final class Application
{
    private int $userId = 1;

    public function __construct(private PDO $db) {}

    public function run(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        try {
            $method = $_SERVER['REQUEST_METHOD'];
            $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            if ($method === 'GET' && $path === '/api/health') {
                $this->respond(['status' => 'ok']);
                return;
            }
            // Local demonstration only: this is a persona selector, not authentication.
            $header = $_SERVER['HTTP_X_DEMO_USER'] ?? '1';
            if (!ctype_digit($header) || !$this->one('SELECT id FROM users WHERE id = ?', [(int) $header])) {
                throw new ApiError('Неизвестный демо-пользователь', 401);
            }
            $this->userId = (int) $header;
            $this->dispatch($method, $path);
        } catch (ApiError $error) {
            http_response_code($error->getCode());
            echo json_encode(['error' => $error->getMessage()], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (Throwable $error) {
            error_log($error->__toString());
            http_response_code(500);
            echo json_encode(['error' => 'Внутренняя ошибка сервера'], JSON_UNESCAPED_UNICODE);
        }
    }

    private function dispatch(string $method, string $path): void
    {
        if ($method === 'GET' && $path === '/api/context') {
            $this->respond([
                'user' => $this->one('SELECT * FROM users WHERE id = ?', [$this->userId]),
                'users' => $this->all('SELECT * FROM users ORDER BY id'),
                'server_now' => gmdate('Y-m-d\TH:i:s\Z'),
                'seeded_at' => $this->db->query("SELECT value FROM metadata WHERE key = 'seeded_at'")->fetchColumn(),
            ]);
            return;
        }
        if ($method === 'GET' && $path === '/api/projects') {
            $projects = $this->all('SELECT p.*, (SELECT COUNT(*) FROM tasks t WHERE t.project_id=p.id) AS task_count FROM projects p JOIN project_members m ON m.project_id=p.id WHERE m.user_id=? ORDER BY p.archived,p.id', [$this->userId]);
            foreach ($projects as &$project) {
                $project['archived'] = (bool) $project['archived'];
                $project['members'] = $this->all('SELECT u.* FROM users u JOIN project_members m ON m.user_id=u.id WHERE m.project_id=? ORDER BY u.id', [$project['id']]);
            }
            $this->respond($projects);
            return;
        }
        if ($method === 'GET' && $path === '/api/attention') {
            $now = new DateTimeImmutable(gmdate('Y-m-d\TH:i:s\Z'), new DateTimeZone('UTC'));
            $today = $now->setTime(0, 0, 0);
            $endToday = $today->modify('+1 day')->modify('-1 second');
            $nextTwoEnd = $today->modify('+3 days')->modify('-1 second');

            $tasks = $this->all(
                'SELECT t.*, p.name AS project_name, u.name AS assignee_name
                   FROM tasks t
                   JOIN projects p ON p.id=t.project_id
                   JOIN project_members member ON member.project_id=p.id
                   LEFT JOIN users u ON u.id=t.assignee_id
                  WHERE p.owner_id=? AND member.user_id=? AND p.archived=0 AND t.status<>\'done\'
                  ORDER BY t.id',
                [$this->userId, $this->userId]
            );

            $attention = [];
            foreach ($tasks as $task) {
                $reasons = [];
                $openRequests = $this->all(
                    'SELECT hr.id, hr.user_id, hr.reason, hr.created_at, u.name AS author
                       FROM help_requests hr
                       JOIN users u ON u.id=hr.user_id
                      WHERE hr.task_id=? AND hr.status=\'open\'
                      ORDER BY hr.created_at ASC, hr.id ASC',
                    [$task['id']]
                );

                $due = null;
                $isOverdue = false;
                $isToday = false;
                $isNextTwo = false;
                if ($task['due_at'] !== null) {
                    $due = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $task['due_at'], new DateTimeZone('UTC'));
                    if ($due) {
                        $isOverdue = $due < $now;
                        $isToday = !$isOverdue && $due <= $endToday;
                        $isNextTwo = !$isOverdue && !$isToday && $due <= $nextTwoEnd;
                    }
                }

                if ($openRequests) {
                    $reasons[] = 'open_request';
                }
                if ($task['status'] === 'blocked') {
                    $reasons[] = 'blocked';
                }
                if ($isOverdue) {
                    $reasons[] = 'overdue';
                } elseif ($isToday) {
                    $reasons[] = 'today';
                } elseif ($isNextTwo) {
                    $reasons[] = 'next_2_days';
                }

                if (!$reasons) {
                    continue;
                }

                if ($openRequests) {
                    $priority = 1;
                } elseif ($task['status'] === 'blocked' && $isOverdue) {
                    $priority = 2;
                } elseif ($isOverdue) {
                    $priority = 3;
                } elseif ($task['status'] === 'blocked') {
                    $priority = 4;
                } elseif ($isToday) {
                    $priority = 5;
                } else {
                    $priority = 6;
                }

                $attention[] = [
                    'task_id' => (int) $task['id'],
                    'project_id' => (int) $task['project_id'],
                    'project_name' => $task['project_name'],
                    'title' => $task['title'],
                    'assignee_id' => $task['assignee_id'] === null ? null : (int) $task['assignee_id'],
                    'assignee_name' => $task['assignee_name'] ?? null,
                    'due_at' => $task['due_at'],
                    'status' => $task['status'],
                    'priority' => $priority,
                    'reasons' => $reasons,
                    'open_requests' => $openRequests,
                ];
            }

            usort($attention, function (array $a, array $b): int {
                if ($a['priority'] !== $b['priority']) {
                    return $a['priority'] <=> $b['priority'];
                }

                $aDue = $a['due_at'];
                $bDue = $b['due_at'];
                if ($aDue !== null && $bDue !== null && $aDue !== $bDue) {
                    return strcmp($aDue, $bDue);
                }
                if ($aDue !== null && $bDue === null) return -1;
                if ($aDue === null && $bDue !== null) return 1;

                if ($aDue === null && $a['open_requests']) {
                    $aRequest = $a['open_requests'][0]['created_at'];
                    $bRequest = $b['open_requests'][0]['created_at'] ?? '';
                    if ($aRequest !== $bRequest) return strcmp($aRequest, $bRequest);
                }
                return $a['task_id'] <=> $b['task_id'];
            });

            $this->respond($attention);
            return;
        }
        if ($method === 'GET' && $path === '/api/help-requests') {
            $requests = $this->all(
                'SELECT hr.id, hr.task_id, hr.user_id, hr.reason, hr.created_at, hr.status, hr.resolved_at, hr.resolved_by,
                        u.name AS author, ru.name AS resolver,
                        t.title AS task_title, t.status AS task_status,
                        p.id AS project_id, p.name AS project_name
                   FROM help_requests hr
                   JOIN tasks t ON t.id=hr.task_id
                   JOIN projects p ON p.id=t.project_id
                   JOIN project_members member ON member.project_id=p.id
                   JOIN users u ON u.id=hr.user_id
                   LEFT JOIN users ru ON ru.id=hr.resolved_by
                  WHERE hr.status=\'open\' AND p.owner_id=? AND member.user_id=? AND p.archived=0
                  ORDER BY hr.created_at DESC, hr.id DESC',
                [$this->userId, $this->userId]
            );
            $this->respond($requests);
            return;
        }
        if ($method === 'POST' && preg_match('#^/api/help-requests/(\d+)/resolve$#', $path, $match)) {
            $requestId = (int) $match[1];
            $request = $this->one(
                'SELECT hr.*, t.project_id, p.owner_id, p.archived
                   FROM help_requests hr
                   JOIN tasks t ON t.id=hr.task_id
                   JOIN projects p ON p.id=t.project_id
                   JOIN project_members member ON member.project_id=p.id
                  WHERE hr.id=? AND member.user_id=?',
                [$requestId, $this->userId]
            );
            if (!$request || (int) $request['owner_id'] !== $this->userId || (bool) $request['archived']) {
                throw new ApiError('Запрос на помощь не найден', 404);
            }
            if ($request['status'] !== 'open') {
                throw new ApiError('Запрос на помощь уже решён', 409);
            }
            $resolvedAt = gmdate('Y-m-d\TH:i:s\Z');
            $stmt = $this->db->prepare("UPDATE help_requests SET status='resolved', resolved_at=?, resolved_by=? WHERE id=? AND status='open'");
            $stmt->execute([$resolvedAt, $this->userId, $requestId]);
            if ($stmt->rowCount() !== 1) {
                throw new ApiError('Запрос на помощь уже решён', 409);
            }
            $resolved = $this->one(
                'SELECT hr.*, u.name AS author, ru.name AS resolver
                   FROM help_requests hr
                   JOIN users u ON u.id=hr.user_id
                   LEFT JOIN users ru ON ru.id=hr.resolved_by
                  WHERE hr.id=?',
                [$requestId]
            );
            $this->respond($resolved);
            return;
        }
        if (preg_match('#^/api/projects/(\d+)/tasks$#', $path, $match)) {
            $project = $this->project((int) $match[1]);
            if ($method === 'GET') {
                $this->respond($this->all(
                    'SELECT t.* FROM tasks t WHERE t.project_id=? ORDER BY t.id',
                    [$project['id']]
                ));
                return;
            }
            if ($method === 'POST') {
                $this->writable($project);
                $data = $this->input();
                $fields = $this->validateTask($data, $project['id'], false);
                $stmt = $this->db->prepare('INSERT INTO tasks (project_id,title,description,status,assignee_id,due_at,updated_at) VALUES (?,?,?,?,?,?,?)');
                $stmt->execute([$project['id'], $fields['title'], $fields['description'], $fields['status'], $fields['assignee_id'], $fields['due_at'], gmdate('Y-m-d\TH:i:s\Z')]);
                $this->respond($this->task((int) $this->db->lastInsertId()), 201);
                return;
            }
        }
        if ($method === 'POST' && preg_match('#^/api/tasks/(\d+)/help-requests$#', $path, $match)) {
            $task = $this->task((int) $match[1]);
            $this->writable($this->project($task['project_id']));
            if ($task['status'] === 'done') {
                throw new ApiError('Завершённая задача не принимает новые запросы на помощь', 409);
            }
            $data = $this->input();
            $unknown = array_diff(array_keys($data), ['reason']);
            if ($unknown) {
                throw new ApiError('Неизвестные поля запроса на помощь', 422);
            }
            $reason = $this->text($data['reason'] ?? null, 'Причина обращения', 5000, true);
            $existing = $this->one("SELECT id FROM help_requests WHERE task_id=? AND status='open'", [$task['id']]);
            if ($existing) {
                throw new ApiError('На эту задачу уже есть открытый запрос на помощь', 409);
            }
            try {
                $stmt = $this->db->prepare('INSERT INTO help_requests (task_id,user_id,reason,created_at,status) VALUES (?,?,?,?,\'open\')');
                $stmt->execute([$task['id'], $this->userId, $reason, gmdate('Y-m-d\TH:i:s\Z')]);
            } catch (PDOException $error) {
                if ((int) $error->getCode() === 23000 || str_contains($error->getMessage(), 'UNIQUE constraint')) {
                    throw new ApiError('На эту задачу уже есть открытый запрос на помощь', 409);
                }
                throw $error;
            }
            $requestId = (int) $this->db->lastInsertId();
            $request = $this->one(
                'SELECT hr.*, u.name AS author, ru.name AS resolver
                   FROM help_requests hr
                   JOIN users u ON u.id=hr.user_id
                   LEFT JOIN users ru ON ru.id=hr.resolved_by
                  WHERE hr.id=?',
                [$requestId]
            );
            $this->respond($request, 201);
            return;
        }
        if (preg_match('#^/api/tasks/(\d+)$#', $path, $match)) {
            $task = $this->task((int) $match[1]);
            if ($method === 'GET') {
                $task['comments'] = $this->all('SELECT c.*, u.name AS author FROM comments c JOIN users u ON u.id=c.user_id WHERE task_id=? ORDER BY c.id', [$task['id']]);
                $task['help_requests'] = $this->all(
                    'SELECT hr.*, u.name AS author, ru.name AS resolver
                       FROM help_requests hr
                       JOIN users u ON u.id=hr.user_id
                       LEFT JOIN users ru ON ru.id=hr.resolved_by
                      WHERE hr.task_id=? ORDER BY hr.created_at DESC, hr.id DESC',
                    [$task['id']]
                );
                $this->respond($task);
                return;
            }
            if ($method === 'PATCH') {
                $this->writable($this->project($task['project_id']));
                $data = $this->input();
                $fields = $this->validateTask($data, $task['project_id'], true);
                if (!isset($data['version']) || !is_int($data['version'])) {
                    throw new ApiError('Нужна целочисленная версия задачи', 422);
                }
                $updated = array_merge($task, $fields);
                $stmt = $this->db->prepare('UPDATE tasks SET title=?,description=?,status=?,assignee_id=?,due_at=?,updated_at=?,version=version+1 WHERE id=? AND version=?');
                $stmt->execute([$updated['title'], $updated['description'], $updated['status'], $updated['assignee_id'], $updated['due_at'], gmdate('Y-m-d\TH:i:s\Z'), $task['id'], $data['version']]);
                if ($stmt->rowCount() !== 1) {
                    throw new ApiError('Задача изменилась. Откройте её заново перед сохранением.', 409);
                }
                $this->respond($this->task($task['id']));
                return;
            }
        }
        if ($method === 'POST' && preg_match('#^/api/tasks/(\d+)/comments$#', $path, $match)) {
            $task = $this->task((int) $match[1]);
            $this->writable($this->project($task['project_id']));

            $data = $this->input();
            $body = $this->text(
                $data['body'] ?? null,
                'Комментарий',
                5000,
                true
            );

            $createdAt = gmdate('Y-m-d\TH:i:s\Z');

            $stmt = $this->db->prepare(
                'INSERT INTO comments (task_id, user_id, body, created_at) VALUES (?, ?, ?, ?)'
            );

            $stmt->execute([
                $task['id'],
                $this->userId,
                $body,
                $createdAt
            ]);

            $commentId = (int) $this->db->lastInsertId();

            $comment = $this->one(
                'SELECT c.*, u.name AS author
                     FROM comments c
                     JOIN users u ON u.id = c.user_id
                     WHERE c.id = ?',
                [$commentId]
            );

            $this->respond($comment, 201);
            return;
        }
        throw new ApiError('Маршрут не найден', 404);
    }

    private function validateTask(array $data, int $projectId, bool $partial): array
    {
        $unknown = array_diff(array_keys($data), ['title', 'description', 'status', 'assignee_id', 'due_at', 'version']);
        if ($unknown) {
            throw new ApiError('Неизвестные поля задачи', 422);
        }
        $out = [];
        if (!$partial || array_key_exists('title', $data)) {
            $out['title'] = $this->text($data['title'] ?? null, 'Название', 200, true);
        }
        if (!$partial || array_key_exists('description', $data)) {
            $out['description'] = $this->text($data['description'] ?? '', 'Описание', 10000, false);
        }
        if (!$partial || array_key_exists('status', $data)) {
            $status = $data['status'] ?? 'open';
            if (!in_array($status, ['open', 'in_progress', 'blocked', 'done'], true)) {
                throw new ApiError('Недопустимый статус', 422);
            }
            $out['status'] = $status;
        }
        if (!$partial || array_key_exists('assignee_id', $data)) {
            $id = $data['assignee_id'] ?? null;
            if ($id !== null && (!is_int($id) || !$this->one('SELECT 1 FROM project_members WHERE project_id=? AND user_id=?', [$projectId, $id]))) {
                throw new ApiError('Исполнитель должен быть участником проекта', 422);
            }
            $out['assignee_id'] = $id;
        }
        if (!$partial || array_key_exists('due_at', $data)) {
            $due = $data['due_at'] ?? null;
            if ($due !== null) {
                if (!is_string($due)) {
                    throw new ApiError('Срок должен быть ISO 8601 в UTC или null', 422);
                }
                $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $due, new DateTimeZone('UTC'));
                if (!$date || $date->format('Y-m-d\TH:i:s\Z') !== $due) {
                    throw new ApiError('Срок должен быть ISO 8601 в UTC или null', 422);
                }
            }
            $out['due_at'] = $due;
        }
        return $out;
    }

    private function text(mixed $value, string $name, int $max, bool $required): string
    {
        if (!is_string($value) || ($required && trim($value) === '') || strlen($value) > $max) {
            throw new ApiError($name . ': некорректная строка или превышен лимит байтов ' . $max, 422);
        }
        return trim($value);
    }

    private function input(): array
    {
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 32768) {
            throw new ApiError('Слишком большой запрос', 413);
        }
        $raw = file_get_contents('php://input', false, null, 0, 32769);
        if (strlen($raw) > 32768) {
            throw new ApiError('Слишком большой запрос', 413);
        }
        try {
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ApiError('Некорректный JSON', 400);
        }
        if (!is_array($data) || !str_starts_with(ltrim($raw), '{')) {
            throw new ApiError('Ожидается JSON-объект', 400);
        }
        return $data;
    }

    private function project(int $id): array
    {
        $project = $this->one('SELECT p.* FROM projects p JOIN project_members m ON m.project_id=p.id WHERE p.id=? AND m.user_id=?', [$id, $this->userId]);
        if (!$project) {
            throw new ApiError('Проект не найден', 404);
        }
        return $project;
    }

    private function task(int $id): array
    {
        $task = $this->one('SELECT t.* FROM tasks t JOIN project_members m ON m.project_id=t.project_id WHERE t.id=? AND m.user_id=?', [$id, $this->userId]);
        if (!$task) {
            throw new ApiError('Задача не найдена', 404);
        }
        return $task;
    }

    private function writable(array $project): void
    {
        if ($project['archived']) {
            throw new ApiError('Архивный проект доступен только для чтения', 409);
        }
    }

    private function one(string $sql, array $params = []): array|false
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    }

    private function all(string $sql, array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    private function respond(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        echo json_encode(['data' => $data], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
