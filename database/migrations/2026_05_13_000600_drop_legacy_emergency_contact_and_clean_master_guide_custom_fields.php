<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $this->cleanMemberCustomFields();
        $this->dropLegacyEmergencyContact('member_master_guides');
        $this->dropLegacyEmergencyContact('staff_master_guides');
    }

    public function down(): void
    {
        foreach (['member_master_guides', 'staff_master_guides'] as $tableName) {
            if (!Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'emergency_contact')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->string('emergency_contact')->nullable();
            });

            if (Schema::hasColumn($tableName, 'emergency_contact_name')) {
                DB::table($tableName)->update([
                    'emergency_contact' => DB::raw('emergency_contact_name'),
                ]);
            }
        }
    }

    private function cleanMemberCustomFields(): void
    {
        if (
            !Schema::hasTable('member_master_guides')
            || !Schema::hasColumn('member_master_guides', 'custom_fields_json')
            || !Schema::hasTable('master_guide_member_form_schemas')
        ) {
            return;
        }

        $fieldsByClub = DB::table('master_guide_member_form_schemas')
            ->get(['club_id', 'schema_json'])
            ->mapWithKeys(fn ($schema) => [
                (int) $schema->club_id => $this->schemaFields($this->decodeJson($schema->schema_json)),
            ])
            ->all();

        DB::table('member_master_guides')
            ->whereNotNull('custom_fields_json')
            ->chunkById(100, function ($rows) use ($fieldsByClub) {
                foreach ($rows as $row) {
                    $values = $this->decodeJson($row->custom_fields_json);
                    if (!is_array($values)) {
                        continue;
                    }

                    $clean = [];
                    foreach ($fieldsByClub[(int) $row->club_id] ?? [] as $field) {
                        $key = $field['key'] ?? null;
                        if ($key && array_key_exists($key, $values)) {
                            $clean[$key] = $values[$key];
                        }
                    }

                    DB::table('member_master_guides')
                        ->where('id', $row->id)
                        ->update([
                            'custom_fields_json' => empty($clean) ? null : json_encode($clean),
                        ]);
                }
            });
    }

    private function dropLegacyEmergencyContact(string $tableName): void
    {
        if (!Schema::hasTable($tableName) || !Schema::hasColumn($tableName, 'emergency_contact')) {
            return;
        }

        if (Schema::hasColumn($tableName, 'emergency_contact_name')) {
            DB::table($tableName)
                ->whereNull('emergency_contact_name')
                ->whereNotNull('emergency_contact')
                ->update(['emergency_contact_name' => DB::raw('emergency_contact')]);
        }

        Schema::table($tableName, function (Blueprint $table) {
            $table->dropColumn('emergency_contact');
        });
    }

    private function schemaFields(?array $schema): array
    {
        $fields = is_array($schema['fields'] ?? null) ? $schema['fields'] : [];
        $normalized = [];
        $seen = [];

        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }

            $label = trim((string) ($field['label'] ?? ''));
            $key = Str::of((string) ($field['key'] ?? $label))
                ->lower()
                ->replaceMatches('/[^a-z0-9]+/', '_')
                ->trim('_')
                ->toString();

            if ($key === '' || isset($seen[$key])) {
                continue;
            }

            $normalized[] = ['key' => $key];
            $seen[$key] = true;
        }

        return $normalized;
    }

    private function decodeJson($value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }
};
