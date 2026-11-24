<?php

namespace Tests\Unit;

use App\Http\Controllers\Volunteer\ReleaseController;
use PHPUnit\Framework\TestCase;

class EndSentenceTest extends TestCase
{
    public function test_a_name_ending_in_a_period_gets_no_second_one(): void
    {
        $this->assertSame('Jordan P.', ReleaseController::endSentence('Jordan P.'));
    }

    public function test_a_name_without_one_gets_one(): void
    {
        $this->assertSame('Cher.', ReleaseController::endSentence('Cher'));
        $this->assertSame('Front desk, 3 to 6pm.', ReleaseController::endSentence('Front desk, 3 to 6pm'));
    }
}
