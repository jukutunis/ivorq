<?php

namespace Tests\Feature\Operations\Inventory;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Foundation\User\Models\User;
use Modules\Foundation\Property\Models\Property;
use Modules\Operations\Inventory\Models\StockCountSession;
use Modules\Operations\Inventory\Models\StockCountAttachment;
use Modules\Operations\Inventory\Enums\CountScopeEnum;
use Modules\Operations\Inventory\Enums\SessionTypeEnum;
use Modules\Operations\Inventory\Enums\CountStatusEnum;

class StockOpnameAttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $property;
    protected $session;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->artisan('db:seed');
        
        $this->user = User::first();
        $this->property = Property::first();
        
        $this->actingAs($this->user);

        $this->session = StockCountSession::create([
            'property_id' => $this->property->id,
            'session_number' => 'OPN-ATT-001',
            'type' => SessionTypeEnum::FULL_COUNT->value,
            'scope' => CountScopeEnum::PROPERTY->value,
            'status' => CountStatusEnum::DRAFT->value,
        ]);
    }

    public function test_attachment_creation_and_retrieval()
    {
        $attachment = StockCountAttachment::create([
            'property_id' => $this->property->id,
            'stock_count_session_id' => $this->session->id,
            'filename' => 'evidence.jpg',
            'path' => 'attachments/evidence.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 1024,
            'uploaded_by' => $this->user->id,
        ]);

        $this->assertDatabaseHas('stock_count_attachments', [
            'id' => $attachment->id,
            'filename' => 'evidence.jpg',
            'uploaded_by' => $this->user->id,
        ]);

        $retrieved = StockCountAttachment::find($attachment->id);
        $this->assertNotNull($retrieved);
        $this->assertEquals('evidence.jpg', $retrieved->filename);
    }

    public function test_session_relationship()
    {
        $attachment = StockCountAttachment::create([
            'property_id' => $this->property->id,
            'stock_count_session_id' => $this->session->id,
            'filename' => 'evidence2.jpg',
            'path' => 'attachments/evidence2.jpg',
            'uploaded_by' => $this->user->id,
        ]);

        // Test belongsTo
        $this->assertEquals($this->session->id, $attachment->session->id);

        // Test hasMany
        $this->assertCount(1, $this->session->attachments);
        $this->assertEquals($attachment->id, $this->session->attachments->first()->id);
    }

    public function test_property_isolation()
    {
        $otherProperty = Property::skip(1)->first();

        $attachment = StockCountAttachment::create([
            'property_id' => $this->property->id,
            'stock_count_session_id' => $this->session->id,
            'filename' => 'evidence3.jpg',
            'path' => 'attachments/evidence3.jpg',
            'uploaded_by' => $this->user->id,
        ]);

        $this->assertEquals($this->property->id, $attachment->property->id);
        $this->assertNotEquals($otherProperty->id, $attachment->property->id);
    }

    public function test_delete_behavior()
    {
        $attachment = StockCountAttachment::create([
            'property_id' => $this->property->id,
            'stock_count_session_id' => $this->session->id,
            'filename' => 'evidence_del.jpg',
            'path' => 'attachments/evidence_del.jpg',
            'uploaded_by' => $this->user->id,
        ]);

        $attachmentId = $attachment->id;

        $attachment->delete();

        $this->assertDatabaseMissing('stock_count_attachments', [
            'id' => $attachmentId,
        ]);
    }
}
