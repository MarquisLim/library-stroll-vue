<?php

namespace Tests\Unit;

use App\Services\Search\ReciprocalRankFusion;
use PHPUnit\Framework\TestCase;

class ReciprocalRankFusionTest extends TestCase
{
    private ReciprocalRankFusion $rrf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rrf = new ReciprocalRankFusion();
    }

    public function test_single_list_preserves_order(): void
    {
        $list = [['id' => 1], ['id' => 2], ['id' => 3]];

        $result = $this->rrf->fuse([$list], k: 60, limit: 10);

        $this->assertSame([1, 2, 3], array_column($result, 'id'));
    }

    public function test_overlapping_items_rank_higher(): void
    {
        $list1 = [['id' => 1], ['id' => 2], ['id' => 3]];
        $list2 = [['id' => 2], ['id' => 3], ['id' => 4]];

        $result = $this->rrf->fuse([$list1, $list2], k: 60, limit: 10);
        $ids = array_column($result, 'id');

        $this->assertSame(2, $ids[0], 'ID 2 should rank first (appears in both lists at good positions)');
        $this->assertSame(3, $ids[1], 'ID 3 should rank second');
    }

    public function test_limit_is_respected(): void
    {
        $list = [['id' => 1], ['id' => 2], ['id' => 3], ['id' => 4], ['id' => 5]];

        $result = $this->rrf->fuse([$list], k: 60, limit: 3);

        $this->assertCount(3, $result);
    }

    public function test_empty_lists_return_empty(): void
    {
        $result = $this->rrf->fuse([], k: 60, limit: 10);

        $this->assertSame([], $result);
    }

    public function test_disjoint_lists_interleave_by_position(): void
    {
        $list1 = [['id' => 1], ['id' => 2]];
        $list2 = [['id' => 3], ['id' => 4]];

        $result = $this->rrf->fuse([$list1, $list2], k: 60, limit: 10);
        $ids = array_column($result, 'id');

        $this->assertCount(4, $ids);
        $this->assertContains(1, $ids);
        $this->assertContains(3, $ids);
    }

    public function test_scores_are_positive_floats(): void
    {
        $list = [['id' => 1], ['id' => 2]];

        $result = $this->rrf->fuse([$list], k: 60, limit: 10);

        foreach ($result as $item) {
            $this->assertGreaterThan(0, $item['score']);
            $this->assertIsFloat($item['score']);
        }
    }

    public function test_accepts_plain_id_values(): void
    {
        $list = [1, 2, 3];

        $result = $this->rrf->fuse([$list], k: 60, limit: 10);

        $this->assertCount(3, $result);
    }
}
