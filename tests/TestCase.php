<?php

namespace Tests;

use App\Models\Building;
use App\Models\Room;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Tests written before building management create rooms directly.
        // Keep those fixtures representative of a valid, assigned room.
        Room::creating(function (Room $room) {
            if (! $room->building_id) {
                $room->building_id = Building::firstOrCreate(
                    ['code' => 'TEST-BLDG'],
                    ['name' => 'Test Building', 'is_active' => true]
                )->id;
            }
        });
    }
}
