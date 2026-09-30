<?php

namespace Illuminate\Tests\Integration\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\DatabasePresenceVerifier;
use Illuminate\Validation\Rule;
use Throwable;

class DatabaseRuleFalseProbeTest extends DatabaseTestCase
{
    protected function afterRefreshingDatabase()
    {
        Schema::create('probe', function (Blueprint $table) {
            $table->integer('id');
            $table->boolean('bool_col');
            $table->tinyInteger('tiny_col');
            $table->string('str_col');
        });

        DB::table('probe')->insert([
            ['id' => 1, 'bool_col' => false, 'tiny_col' => 0, 'str_col' => ''],
            ['id' => 2, 'bool_col' => true, 'tiny_col' => 1, 'str_col' => 'x'],
        ]);
    }

    public function testProbe()
    {
        $verifier = new DatabasePresenceVerifier($this->app['db']);
        $lines = ["PROBE driver={$this->driver} version=".DB::connection()->getServerVersion()];

        // Correct results: "= false" matches only row 1 (1/0), "!= false" matches only row 2 (0/1).
        foreach (['bool_col', 'tiny_col', 'str_col'] as $column) {
            foreach (['old =' => '', 'new =' => '0', 'old !=' => '!', 'new !=' => '!0'] as $label => $value) {
                try {
                    $result = 'row1='.$verifier->getCount('probe', 'id', 1, null, null, [$column => $value])
                        .' row2='.$verifier->getCount('probe', 'id', 2, null, null, [$column => $value]);
                } catch (Throwable $e) {
                    $result = 'ERROR '.strtok($e->getMessage(), "\n");
                }

                $lines[] = sprintf('PROBE %-8s %-6s value=%-4s => %s', $column, $label, json_encode($value), $result);
            }
        }

        foreach (['where' => [1, false], 'whereNot' => [2, false]] as $method => [$duplicateId, $value]) {
            try {
                $fails = Validator::make(['id' => $duplicateId], ['id' => Rule::unique('probe', 'id')->{$method}('bool_col', $value)])->fails();
                $lines[] = "PROBE rule {$method}(bool_col, false) id={$duplicateId} => ".($fails ? 'duplicate caught' : 'DUPLICATE MISSED');
            } catch (Throwable $e) {
                $lines[] = "PROBE rule {$method}(bool_col, false) => ERROR ".strtok($e->getMessage(), "\n");
            }
        }

        fwrite(STDERR, PHP_EOL.implode(PHP_EOL, $lines).PHP_EOL);

        $this->assertTrue(true);
    }
}
