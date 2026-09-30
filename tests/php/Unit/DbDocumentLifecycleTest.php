<?php

declare(strict_types=1);

namespace SanctumTasks\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Documents (long-form markdown reference material attached to a project)
 * + their per-document discussion thread.
 *
 * Uses bootstrap SQLite (shared temp DB per PHPUnit process).
 */
final class DbDocumentLifecycleTest extends TestCase
{
    private function makeUserAndProject(string $tag): array
    {
        $suffix = $tag . '_' . bin2hex(random_bytes(4));
        $user = createUser("doc_{$suffix}", 'DocPass123456', 'admin', false);
        $this->assertTrue($user['success'], (string)($user['error'] ?? 'create user'));
        $uid = (int)$user['id'];

        $proj = createDirectoryProject($uid, "DocProj {$suffix}", null, false, true);
        $this->assertTrue($proj['success'], (string)($proj['error'] ?? 'create project'));
        return [$uid, (int)$proj['id'], $suffix];
    }

    public function testCreateDocumentRequiresValidProject(): void
    {
        [$uid, $pid] = $this->makeUserAndProject('a');

        $noTitle = createDocument($uid, $pid, '   ', 'body');
        $this->assertFalse($noTitle['success']);

        $badProject = createDocument($uid, 999999, 'Whatever', 'body');
        $this->assertFalse($badProject['success']);

        $noProjectId = createDocument($uid, 0, 'Whatever', 'body');
        $this->assertFalse($noProjectId['success']);
        $this->assertSame('project_id is required', $noProjectId['error'] ?? null);

        $ok = createDocument($uid, $pid, 'Spec — onboarding flow', "# Title\n\nBody **bold**.");
        $this->assertTrue($ok['success'], (string)($ok['error'] ?? 'create doc'));

        $loaded = getDocumentById((int)$ok['id']);
        $this->assertNotNull($loaded);
        $this->assertSame('Spec — onboarding flow', $loaded['title']);
        $this->assertSame($pid, (int)$loaded['project_id']);
        $this->assertSame(0, (int)$loaded['comment_count']);
        $this->assertSame([], $loaded['comments']);
        $this->assertSame((string)$pid, (string)$loaded['project_id']);
    }

    public function testUpdateAndDeleteDocument(): void
    {
        [$uid, $pid] = $this->makeUserAndProject('b');
        $doc = createDocument($uid, $pid, 'Initial', 'body 1');
        $this->assertTrue($doc['success']);
        $id = (int)$doc['id'];

        $upd = updateDocument($id, ['title' => 'Renamed', 'body' => "# Renamed\n\nbody 2"]);
        $this->assertTrue($upd['success'], (string)($upd['error'] ?? 'update'));
        $loaded = getDocumentById($id, false);
        $this->assertSame('Renamed', $loaded['title']);
        $this->assertStringContainsString('body 2', (string)$loaded['body']);

        $bad = updateDocument($id, ['status' => 'banana']);
        $this->assertFalse($bad['success']);

        $archive = updateDocument($id, ['status' => 'archived']);
        $this->assertTrue($archive['success']);

        $del = deleteDocument($id);
        $this->assertTrue($del['success']);
        $this->assertNull(getDocumentById($id));
    }

    public function testDocumentCommentLifecycle(): void
    {
        [$uid, $pid] = $this->makeUserAndProject('c');
        $doc = createDocument($uid, $pid, 'Doc with discussion', null);
        $this->assertTrue($doc['success']);
        $docId = (int)$doc['id'];

        $empty = addDocumentComment($docId, $uid, '   ');
        $this->assertFalse($empty['success']);

        $first = addDocumentComment($docId, $uid, 'first reply');
        $this->assertTrue($first['success']);
        $second = addDocumentComment($docId, $uid, 'second reply with **markdown**');
        $this->assertTrue($second['success']);

        $list = listDocumentComments($docId, 100, 0);
        $this->assertCount(2, $list);
        $this->assertSame('first reply', $list[0]['comment']);
        $this->assertSame('second reply with **markdown**', $list[1]['comment']);

        $reloaded = getDocumentById($docId);
        $this->assertSame(2, (int)$reloaded['comment_count']);
        $this->assertCount(2, $reloaded['comments']);
    }

    public function testListDocumentsFiltersAndOrdersByProject(): void
    {
        [$uid1, $pidA, $sufA] = $this->makeUserAndProject('a2');
        $docA1 = createDocument($uid1, $pidA, "A1 {$sufA}", null);
        $this->assertTrue($docA1['success']);
        $docA2 = createDocument($uid1, $pidA, "A2 {$sufA}", null);
        $this->assertTrue($docA2['success']);

        [$uid2, $pidB, $sufB] = $this->makeUserAndProject('b2');
        $docB1 = createDocument($uid2, $pidB, "B1 {$sufB}", null);
        $this->assertTrue($docB1['success']);

        // Admin uid1 should see all (admin role + all_access projects).
        $u1 = getUserById($uid1, false);
        $allForAdmin = listDocumentsForUser($u1, 200);
        $idsAll = array_column($allForAdmin, 'id');
        $this->assertContains((int)$docA1['id'], $idsAll);
        $this->assertContains((int)$docA2['id'], $idsAll);

        $filtered = listDocumentsForUser($u1, 200, $pidA);
        $idsFiltered = array_column($filtered, 'id');
        $this->assertContains((int)$docA1['id'], $idsFiltered);
        $this->assertContains((int)$docA2['id'], $idsFiltered);
        $this->assertNotContains((int)$docB1['id'], $idsFiltered);

        // Updates bump updated_at; list should sort newest first.
        usleep(1100000); // 1.1s — sqlite CURRENT_TIMESTAMP is 1s precision
        $upd = updateDocument((int)$docA1['id'], ['body' => 'touched']);
        $this->assertTrue($upd['success']);
        $relisted = listDocumentsForUser($u1, 200, $pidA);
        $this->assertSame((int)$docA1['id'], (int)$relisted[0]['id']);
    }

    public function testAccessControlBlocksOtherOrgUsers(): void
    {
        [$uid1, $pidA] = $this->makeUserAndProject('access1');
        $doc = createDocument($uid1, $pidA, 'Private spec', 'body');
        $this->assertTrue($doc['success']);
        $docRow = getDocumentById((int)$doc['id'], false);
        $this->assertNotNull($docRow);

        // Make a fresh user in a brand-new org without membership of pidA.
        $foreignSuffix = bin2hex(random_bytes(4));
        $foreignOrg = createOrganization("ForeignOrg {$foreignSuffix}", (int)$uid1);
        $this->assertTrue($foreignOrg['success'], (string)($foreignOrg['error'] ?? 'create org'));
        $stranger = createUser("stranger_{$foreignSuffix}", 'StrangerPass123456', 'member', false, (int)$foreignOrg['id']);
        $this->assertTrue($stranger['success']);
        $strangerRow = getUserById((int)$stranger['id'], false);

        $this->assertFalse(userCanAccessDocument($strangerRow, $docRow));
        $this->assertNotContains((int)$doc['id'], array_column(listDocumentsForUser($strangerRow, 200), 'id'));
    }

    public function testDocumentPublicSharingTokenLifecycle(): void
    {
        [$uid, $pid] = $this->makeUserAndProject('pub');
        $private = createDocument($uid, $pid, 'Internal only', '# Hi', null, false);
        $this->assertTrue($private['success']);
        $id = (int)$private['id'];
        $row0 = getDocumentById($id, false);
        $this->assertNotNull($row0);
        $this->assertFalse((bool)$row0['public_link_enabled']);
        $this->assertTrue($row0['public_link_token'] === null || (string)$row0['public_link_token'] === '');

        $on = documentSetPublicSharing($id, $uid, true, false);
        $this->assertTrue($on['success']);
        $row1 = getDocumentById($id, false);
        $this->assertTrue((bool)$row1['public_link_enabled']);
        $hex = normalizeDocumentPublicLinkHexToken((string)($row1['public_link_token'] ?? ''));
        $this->assertNotNull($hex);

        $pub = getDocumentByPublicLinkToken($hex);
        $this->assertNotNull($pub);
        $this->assertSame($id, (int)$pub['id']);
        $this->assertStringContainsString('# Hi', (string)$pub['body']);

        $rot = documentSetPublicSharing($id, $uid, true, true);
        $this->assertTrue($rot['success']);
        $this->assertNull(getDocumentByPublicLinkToken((string)$hex));
        $row2 = getDocumentById($id, false);
        $hex2 = normalizeDocumentPublicLinkHexToken((string)($row2['public_link_token'] ?? ''));
        $this->assertNotNull($hex2);
        $this->assertNotSame(strtolower((string)$hex), strtolower((string)$hex2));
        $this->assertNotNull(getDocumentByPublicLinkToken((string)$hex2));

        $off = documentSetPublicSharing($id, $uid, false, false);
        $this->assertTrue($off['success']);
        $this->assertNull(getDocumentByPublicLinkToken((string)$hex2));

        $publicAtCreate = createDocument($uid, $pid, 'Born public', '# X', '', true);
        $this->assertTrue($publicAtCreate['success']);
        $rn = getDocumentById((int)$publicAtCreate['id'], false);
        $this->assertTrue((bool)$rn['public_link_enabled']);
        $hn = normalizeDocumentPublicLinkHexToken((string)($rn['public_link_token'] ?? ''));
        $this->assertNotNull($hn);
    }

    public function testDocumentBodyTruncatesAtOneMegabyte(): void
    {
        [$uid, $pid] = $this->makeUserAndProject('maxbody');
        $exact = str_repeat('x', 1000000);
        $over = $exact . 'y';
        $doc = createDocument($uid, $pid, 'Max body', $over);
        $this->assertTrue($doc['success'], (string)($doc['error'] ?? 'create'));
        $loaded = getDocumentById((int)$doc['id'], false);
        $this->assertSame(1000000, strlen((string)$loaded['body']));
        $this->assertSame($exact, $loaded['body']);
    }

    public function testEscapeSqlLikePatternTreatsWildcardsAsLiterals(): void
    {
        $this->assertSame('100\\% done', escapeSqlLikePattern('100% done'));
        $this->assertSame('a\\_b', escapeSqlLikePattern('a_b'));
        $this->assertSame('c\\\\d', escapeSqlLikePattern('c\\d'));
    }

    public function testListDocumentsQMatchesTitleAndBodyCaseInsensitive(): void
    {
        [$uid, $pid, $suf] = $this->makeUserAndProject('qmatch');
        $u = getUserById($uid, false);

        $titleHit = createDocument($uid, $pid, "AlphaInvoice {$suf}", 'unrelated body');
        $this->assertTrue($titleHit['success']);
        $bodyHit = createDocument($uid, $pid, "Other {$suf}", "contains InVoIcE payload {$suf}");
        $this->assertTrue($bodyHit['success']);
        $miss = createDocument($uid, $pid, "NoMatch {$suf}", 'nothing here');
        $this->assertTrue($miss['success']);

        $byTitle = listDocumentsForUser($u, 200, $pid, 'invoice');
        $idsTitle = array_column($byTitle, 'id');
        $this->assertContains((int)$titleHit['id'], $idsTitle);
        $this->assertContains((int)$bodyHit['id'], $idsTitle);
        $this->assertNotContains((int)$miss['id'], $idsTitle);

        $byBodyOnly = listDocumentsForUser($u, 200, $pid, "payload {$suf}");
        $this->assertSame([(int)$bodyHit['id']], array_map('intval', array_column($byBodyOnly, 'id')));

        $empty = listDocumentsForUser($u, 200, $pid, 'zzzz-no-such-token');
        $this->assertSame([], $empty);

        $this->assertSame(2, countDocumentsForUser($u, $pid, 'invoice'));
        $this->assertSame(0, countDocumentsForUser($u, $pid, 'zzzz-no-such-token'));
    }

    public function testListDocumentsQRespectsProjectScopeAndLiteralWildcards(): void
    {
        [$uidA, $pidA, $sufA] = $this->makeUserAndProject('qscopea');
        [$uidB, $pidB, $sufB] = $this->makeUserAndProject('qscopeb');
        $uA = getUserById($uidA, false);

        $inA = createDocument($uidA, $pidA, "Scoped {$sufA}", 'needle_value in A');
        $this->assertTrue($inA['success']);
        $pct = createDocument($uidA, $pidA, "Percent {$sufA}", 'literal 50% complete');
        $this->assertTrue($pct['success']);
        $inB = createDocument($uidB, $pidB, "OtherBoard {$sufB}", 'needle_value in B');
        $this->assertTrue($inB['success']);

        $scoped = listDocumentsForUser($uA, 200, $pidA, 'needle_value');
        $scopedIds = array_map('intval', array_column($scoped, 'id'));
        $this->assertContains((int)$inA['id'], $scopedIds);
        $this->assertNotContains((int)$inB['id'], $scopedIds);

        // '%' in the query must be literal, not "match anything".
        $pctHits = listDocumentsForUser($uA, 200, $pidA, '50%');
        $pctIds = array_map('intval', array_column($pctHits, 'id'));
        $this->assertContains((int)$pct['id'], $pctIds);
        $this->assertNotContains((int)$inA['id'], $pctIds);

        // Underscore in query is literal (would otherwise match any single char).
        $under = listDocumentsForUser($uA, 200, $pidA, 'needle_value');
        $this->assertContains((int)$inA['id'], array_map('intval', array_column($under, 'id')));
    }

    public function testUpdateDocumentOptimisticConcurrencyConflict(): void
    {
        [$uid, $pid] = $this->makeUserAndProject('conflict');
        $doc = createDocument($uid, $pid, 'Race me', 'v1');
        $this->assertTrue($doc['success']);
        $id = (int)$doc['id'];
        $row = getDocumentById($id, false);
        $this->assertNotNull($row);
        $stale = '2000-01-01 00:00:00';

        $conflict = updateDocument($id, [
            'body' => 'stale write',
            'expected_updated_at' => $stale,
        ], $uid);
        $this->assertFalse($conflict['success']);
        $this->assertSame('document.conflict', $conflict['error_code'] ?? null);
        $this->assertSame($id, (int)($conflict['details']['document_id'] ?? 0));
        $this->assertSame($stale, $conflict['details']['expected_updated_at'] ?? null);
        $this->assertNotNull($conflict['details']['current_updated_at'] ?? null);

        $ok = updateDocument($id, [
            'body' => 'fresh write',
            'expected_updated_at' => (string)$row['updated_at'],
        ], $uid);
        $this->assertTrue($ok['success'], (string)($ok['error'] ?? 'fresh'));
        $reloaded = getDocumentById($id, false);
        $this->assertStringContainsString('fresh write', (string)$reloaded['body']);
    }
}
