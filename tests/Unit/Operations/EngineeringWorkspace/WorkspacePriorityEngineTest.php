<?php

namespace Modules\Operations\EngineeringWorkspace\Tests\Unit;

use Modules\Operations\EngineeringWorkspace\Services\WorkspacePriorityEngine;
use PHPUnit\Framework\TestCase;

class WorkspacePriorityEngineTest extends TestCase
{
    public function test_priority_engine_calculates_correctly()
    {
        $engine = new WorkspacePriorityEngine();
        
        $score = $engine->calculateScore([
            'guest_impact' => 100, // 35% -> 35
            'incident_severity' => 50, // 25% -> 12.5
            'sla_risk' => 0, // 20% -> 0
            'asset_criticality' => 100, // 15% -> 15
            'wo_priority' => 100, // 5% -> 5
        ]);

        $this->assertEquals(67.5, $score);
    }
}
