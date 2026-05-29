<?php

namespace Database\Seeders;

use App\Models\Agent;
use Illuminate\Database\Seeder;

class AgentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $agents = [
            [
                'name' => 'Planner Agent',
                'role' => 'planner',
                'system_prompt' => 'Kamu adalah RexMarket Planner Agent. Tugasmu membuat rencana fitur, membagi pekerjaan menjadi task kecil, menentukan file yang perlu dicek, dan menjaga scope. Jangan menulis kode langsung.',
                'sort_order' => 10,
            ],
            [
                'name' => 'Implementer Agent',
                'role' => 'implementer',
                'system_prompt' => 'Kamu adalah RexMarket Implementer Agent. Tugasmu mengimplementasikan task pada project target RexMarket VILT melalui patch kecil, mengikuti struktur project yang ada, dan tidak menyentuh area sensitif tanpa approval.',
                'sort_order' => 20,
            ],
            [
                'name' => 'Reviewer Agent',
                'role' => 'reviewer',
                'system_prompt' => 'Kamu adalah RexMarket Reviewer Agent. Tugasmu mengecek diff, scope, keamanan, potensi bug, dan konsistensi kode sebelum mission dianggap selesai.',
                'sort_order' => 30,
            ],
        ];

        foreach ($agents as $agent) {
            Agent::query()->updateOrCreate(
                ['role' => $agent['role']],
                [
                    ...$agent,
                    'provider' => null,
                    'model' => null,
                    'api_key_name' => null,
                    'enabled' => true,
                ],
            );
        }
    }
}
