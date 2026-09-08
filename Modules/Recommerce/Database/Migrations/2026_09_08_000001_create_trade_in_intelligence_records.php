<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { Schema::create('recommerce_trade_in_intelligence', function(Blueprint $t) {
  $t->bigIncrements('id'); $t->uuid('record_uuid')->unique(); $t->unsignedInteger('business_id');
  $t->string('kind',24); $t->string('target',150); $t->string('status',32); $t->longText('payload_json');
  $t->unsignedInteger('actor_id')->nullable(); $t->string('reason',500); $t->timestamp('created_at');
  $t->index(['business_id','kind','target','id'],'rc_ti_intelligence_lookup');
 }); }
 public function down(): void { Schema::dropIfExists('recommerce_trade_in_intelligence'); }
};
