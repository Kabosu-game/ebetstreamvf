<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('recharge_agents', function (Blueprint $table) {
            // Ajouter la colonne agent_id unique
            $table->string('agent_id', 6)->unique()->after('id');
        });
        
        // Générer des IDs aléatoires pour les agents existants après l'ajout de la colonne
        $agents = \DB::table('recharge_agents')->get();
        foreach ($agents as $agent) {
            do {
                $agentId = str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
            } while (\DB::table('recharge_agents')->where('agent_id', $agentId)->where('id', '!=', $agent->id)->exists());
            
            \DB::table('recharge_agents')->where('id', $agent->id)->update(['agent_id' => $agentId]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('recharge_agents', function (Blueprint $table) {
            $table->dropColumn('agent_id');
        });
    }
};
