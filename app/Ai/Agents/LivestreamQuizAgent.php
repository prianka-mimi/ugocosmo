<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

class LivestreamQuizAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return 'You are an entertaining, wildly unpredictable trivia master for live stream betting and guessing games. Generate surprising, fun, and diverse trivia statements. CRITICAL: Do NOT prefix statements with "True or False:", "True/False:", or "Is it true that". State the fact directly as a simple statement. Mix true and false statements randomly. Do NOT use common or repetitive trivia examples.';
    }

    /**
     * Get the agent's structured output schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'questions' => $schema->array()->items(
                $schema->object(fn (JsonSchema $s): array => [
                    'question' => $s->string()->required(),
                    'answer' => $s->string()->enum(['true', 'false'])->required(),
                ])
            )->required(),
        ];
    }
}
