<?php

declare(strict_types=1);

namespace SanctumTasks\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class HomeBoardFiltersTest extends TestCase
{
    public function testPartialRequestDetection(): void
    {
        $this->assertTrue(st_is_home_results_partial_request(['partial' => 'results']));
        $this->assertFalse(st_is_home_results_partial_request(['partial' => 'other']));
        $this->assertFalse(st_is_home_results_partial_request([]));
    }

    public function testFiltersFromGetIncludingMineAndExcludeDone(): void
    {
        $filters = st_home_board_filters_from_get([
            'q' => ' garage ',
            'status' => 'todo',
            'priority' => 'high',
            'tag' => 'search',
            'project' => 'Alpha',
            'project_id' => '12',
            'exclude_done' => '1',
            'mine' => '1',
            'assigned_to_user_id' => '99',
            'sort_by' => 'title',
            'sort_dir' => 'asc',
        ], 42);

        $this->assertSame('garage', $filters['q']);
        $this->assertSame('todo', $filters['status']);
        $this->assertSame('high', $filters['priority']);
        $this->assertSame('search', $filters['tag']);
        $this->assertSame('Alpha', $filters['project']);
        $this->assertSame(12, $filters['project_id']);
        $this->assertTrue($filters['exclude_done']);
        $this->assertSame('42', $filters['assigned_to_user_id']);
        $this->assertSame('title', $filters['sort_by']);
        $this->assertSame('ASC', $filters['sort_dir']);
    }

    public function testEmptyGetYieldsNullFiltersAndDefaultSort(): void
    {
        $filters = st_home_board_filters_from_get([], null);
        $this->assertNull($filters['status']);
        $this->assertNull($filters['priority']);
        $this->assertNull($filters['project']);
        $this->assertNull($filters['q']);
        $this->assertSame('', $filters['assigned_to_user_id']);
        $this->assertSame('updated_at', $filters['sort_by']);
        $this->assertSame('DESC', $filters['sort_dir']);
        $this->assertArrayNotHasKey('tag', $filters);
        $this->assertArrayNotHasKey('exclude_done', $filters);
        $this->assertArrayNotHasKey('project_id', $filters);
    }
}
