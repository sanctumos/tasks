<?php
/**
 * Idempotent Q Vernal ACL E2E fixtures (member + client users, scoped projects).
 * Safe to run repeatedly on dev/staging/prod seed hosts.
 */
declare(strict_types=1);

const Q_ACL_E2E_PASSWORD = 'E2eQAclPass123456!';
const Q_ACL_E2E_MEMBER_USERNAME = 'e2e_q_member';
const Q_ACL_E2E_CLIENT_USERNAME = 'e2e_q_client';
const Q_ACL_E2E_PROJECT_MEMBER_VISIBLE = 'E2E Q ACL Member Visible';
const Q_ACL_E2E_PROJECT_ADMIN_ONLY = 'E2E Q ACL Admin Only';
const Q_ACL_E2E_LIST_NAME = 'E2E';
const Q_ACL_E2E_TASK_MEMBER_VISIBLE = 'E2E Q ACL marker task (member visible)';
const Q_ACL_E2E_TASK_ADMIN_ONLY = 'E2E Q ACL marker task (admin only)';

/**
 * @return array<string,mixed>
 */
function qAclE2eFindProjectByName(int $orgId, string $name): ?array
{
    $db = getDbConnection();
    $stmt = $db->prepare('SELECT * FROM projects WHERE org_id = :o AND name = :n LIMIT 1');
    $stmt->bindValue(':o', $orgId, SQLITE3_INTEGER);
    $stmt->bindValue(':n', $name, SQLITE3_TEXT);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return $row ?: null;
}

function qAclE2eIsProjectMember(int $projectId, int $userId): bool
{
    $db = getDbConnection();
    $stmt = $db->prepare('SELECT 1 FROM project_members WHERE project_id = :p AND user_id = :u LIMIT 1');
    $stmt->bindValue(':p', $projectId, SQLITE3_INTEGER);
    $stmt->bindValue(':u', $userId, SQLITE3_INTEGER);
    return (bool)$stmt->execute()->fetchArray(SQLITE3_ASSOC);
}

/**
 * @return array{success:bool,id?:int,created?:bool,error?:string}
 */
function qAclE2eEnsureUser(string $username, string $personKind, int $orgId): array
{
    $existing = getUserByUsername($username, false);
    if ($existing) {
        return ['success' => true, 'id' => (int)$existing['id'], 'created' => false];
    }
    $created = createUser($username, Q_ACL_E2E_PASSWORD, 'member', false, $orgId, $personKind, false);
    if (!($created['success'] ?? false)) {
        return ['success' => false, 'error' => (string)($created['error'] ?? 'createUser failed')];
    }
    $uid = (int)$created['id'];
    ensureQBridgeDefaultApiKeyForUser($uid);
    return ['success' => true, 'id' => $uid, 'created' => true];
}

/**
 * @return array{success:bool,id?:int,list_id?:int,created?:bool,error?:string}
 */
function qAclE2eEnsureProject(int $actorUserId, int $orgId, string $name, bool $clientVisible, array $memberUserIds): array
{
    $existing = qAclE2eFindProjectByName($orgId, $name);
    if ($existing) {
        $pid = (int)$existing['id'];
        foreach ($memberUserIds as $uid) {
            if (!qAclE2eIsProjectMember($pid, $uid)) {
                addProjectMember($actorUserId, $pid, $uid, 'member');
            }
        }
        $listId = qAclE2eEnsureTodoList($actorUserId, $pid);
        return ['success' => true, 'id' => $pid, 'list_id' => $listId, 'created' => false];
    }

    $created = createDirectoryProject($actorUserId, $name, 'Q ACL E2E fixture project', $clientVisible, false);
    if (!($created['success'] ?? false)) {
        return ['success' => false, 'error' => (string)($created['error'] ?? 'createDirectoryProject failed')];
    }
    $pid = (int)$created['id'];
    foreach ($memberUserIds as $uid) {
        addProjectMember($actorUserId, $pid, $uid, 'member');
    }
    $listId = qAclE2eEnsureTodoList($actorUserId, $pid);
    return ['success' => true, 'id' => $pid, 'list_id' => $listId, 'created' => true];
}

function qAclE2eEnsureTodoList(int $actorUserId, int $projectId): int
{
    $db = getDbConnection();
    $listId = getFirstTodoListIdForProject($db, $projectId);
    if ($listId !== null) {
        return $listId;
    }
    $created = createTodoList($actorUserId, $projectId, Q_ACL_E2E_LIST_NAME);
    if (!($created['success'] ?? false)) {
        throw new RuntimeException('createTodoList failed: ' . (string)($created['error'] ?? 'unknown'));
    }
    return (int)$created['id'];
}

/**
 * @return array{success:bool,id?:int,created?:bool,error?:string}
 */
function qAclE2eEnsureMarkerTask(int $creatorUserId, int $projectId, int $listId, string $title): array
{
    $db = getDbConnection();
    $stmt = $db->prepare('SELECT id FROM tasks WHERE project_id = :p AND title = :t LIMIT 1');
    $stmt->bindValue(':p', $projectId, SQLITE3_INTEGER);
    $stmt->bindValue(':t', $title, SQLITE3_TEXT);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if ($row) {
        return ['success' => true, 'id' => (int)$row['id'], 'created' => false];
    }

    $proj = getDirectoryProjectById($projectId);
    $created = createTask($title, 'todo', $creatorUserId, $creatorUserId, 'Q ACL E2E fixture marker', [
        'project_id' => $projectId,
        'list_id' => $listId,
        'project' => $proj['name'] ?? null,
    ]);
    if (!($created['success'] ?? false)) {
        return ['success' => false, 'error' => (string)($created['error'] ?? 'createTask failed')];
    }
    return ['success' => true, 'id' => (int)$created['id'], 'created' => true];
}

/**
 * Bootstrap or refresh Q ACL E2E fixtures. Idempotent.
 *
 * @return array{success:bool,manifest?:array<string,mixed>,error?:string}
 */
function bootstrapQAclE2eFixtures(): array
{
    $db = getDbConnection();
    ensureDefaultOrganizationAndUsers($db);
    applySanctumSchemaMigrations($db);

    $adminUsername = getBootstrapAdminUsername();
    $admin = getUserByUsername($adminUsername, false);
    if (!$admin) {
        return ['success' => false, 'error' => 'Bootstrap admin user not found'];
    }
    $actorId = (int)$admin['id'];
    $orgId = (int)($admin['org_id'] ?? getDefaultOrganizationId());
    if ($orgId <= 0) {
        return ['success' => false, 'error' => 'No organization configured'];
    }

    $member = qAclE2eEnsureUser(Q_ACL_E2E_MEMBER_USERNAME, 'team_member', $orgId);
    if (!($member['success'] ?? false)) {
        return ['success' => false, 'error' => 'member: ' . (string)($member['error'] ?? 'failed')];
    }
    $client = qAclE2eEnsureUser(Q_ACL_E2E_CLIENT_USERNAME, 'client', $orgId);
    if (!($client['success'] ?? false)) {
        return ['success' => false, 'error' => 'client: ' . (string)($client['error'] ?? 'failed')];
    }

    $memberId = (int)$member['id'];
    $clientId = (int)$client['id'];

    $visible = qAclE2eEnsureProject($actorId, $orgId, Q_ACL_E2E_PROJECT_MEMBER_VISIBLE, true, [$memberId, $clientId]);
    if (!($visible['success'] ?? false)) {
        return ['success' => false, 'error' => 'member_visible project: ' . (string)($visible['error'] ?? 'failed')];
    }
    $adminOnly = qAclE2eEnsureProject($actorId, $orgId, Q_ACL_E2E_PROJECT_ADMIN_ONLY, false, []);
    if (!($adminOnly['success'] ?? false)) {
        return ['success' => false, 'error' => 'admin_only project: ' . (string)($adminOnly['error'] ?? 'failed')];
    }

    $visiblePid = (int)$visible['id'];
    $visibleListId = (int)$visible['list_id'];
    $adminOnlyPid = (int)$adminOnly['id'];
    $adminOnlyListId = (int)$adminOnly['list_id'];

    $taskVisible = qAclE2eEnsureMarkerTask($actorId, $visiblePid, $visibleListId, Q_ACL_E2E_TASK_MEMBER_VISIBLE);
    if (!($taskVisible['success'] ?? false)) {
        return ['success' => false, 'error' => 'member_visible task: ' . (string)($taskVisible['error'] ?? 'failed')];
    }
    $taskAdminOnly = qAclE2eEnsureMarkerTask($actorId, $adminOnlyPid, $adminOnlyListId, Q_ACL_E2E_TASK_ADMIN_ONLY);
    if (!($taskAdminOnly['success'] ?? false)) {
        return ['success' => false, 'error' => 'admin_only task: ' . (string)($taskAdminOnly['error'] ?? 'failed')];
    }

    ensureQBridgeDefaultApiKeyForUser($actorId);

    $manifest = [
        'org_id' => $orgId,
        'password' => Q_ACL_E2E_PASSWORD,
        'admin' => [
            'username' => $adminUsername,
            'id' => $actorId,
        ],
        'users' => [
            'member' => [
                'username' => Q_ACL_E2E_MEMBER_USERNAME,
                'id' => $memberId,
                'person_kind' => 'team_member',
                'created' => (bool)($member['created'] ?? false),
            ],
            'client' => [
                'username' => Q_ACL_E2E_CLIENT_USERNAME,
                'id' => $clientId,
                'person_kind' => 'client',
                'created' => (bool)($client['created'] ?? false),
            ],
        ],
        'projects' => [
            'member_visible' => [
                'id' => $visiblePid,
                'name' => Q_ACL_E2E_PROJECT_MEMBER_VISIBLE,
                'client_visible' => true,
                'list_id' => $visibleListId,
                'created' => (bool)($visible['created'] ?? false),
            ],
            'admin_only' => [
                'id' => $adminOnlyPid,
                'name' => Q_ACL_E2E_PROJECT_ADMIN_ONLY,
                'client_visible' => false,
                'list_id' => $adminOnlyListId,
                'created' => (bool)($adminOnly['created'] ?? false),
            ],
        ],
        'tasks' => [
            'member_visible_marker' => [
                'id' => (int)$taskVisible['id'],
                'title' => Q_ACL_E2E_TASK_MEMBER_VISIBLE,
                'created' => (bool)($taskVisible['created'] ?? false),
            ],
            'admin_only_marker' => [
                'id' => (int)$taskAdminOnly['id'],
                'title' => Q_ACL_E2E_TASK_ADMIN_ONLY,
                'created' => (bool)($taskAdminOnly['created'] ?? false),
            ],
        ],
    ];

    return ['success' => true, 'manifest' => $manifest];
}
