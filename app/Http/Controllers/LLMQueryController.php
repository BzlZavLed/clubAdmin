<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenAI\Laravel\Facades\OpenAI;
class LLMQueryController extends Controller
{
    public function handle(Request $request)
    {
        $validated = $request->validate([
            'query' => 'required|string',
        ]);

        $userQuery = $validated['query'];

        // Instruction to the LLM to always return raw SQL only
        $systemPrompt = "You are a MySQL expert. Translate natural language requests into safe, syntactically correct SQL queries for a Laravel MySQL database. Only return the SQL code, do not explain or add comments.";

        $response = OpenAI::chat()->create([
            'model' => 'gpt-4o',
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userQuery],
            ],
        ]);

        $sql = $response['choices'][0]['message']['content'];

        try {
            // Run the generated SQL
            $result = DB::select(DB::raw($sql));
            return response()->json([
                'sql' => $sql,
                'result' => $result,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'sql' => $sql,
                'error' => $e->getMessage(),
            ], 422);
        }
    }
}
