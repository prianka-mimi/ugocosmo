<?php

declare(strict_types=1);

namespace Modules\Communication\Services;

use App\Ai\Agents\LivestreamQuizAgent;
use Illuminate\Support\Facades\Log;
use Throwable;

class AiQuestionService
{
    /**
     * Generate trivia True/False questions using Laravel AI SDK with fallback.
     *
     * @return array<int, array{question: string, answer: string}>
     */
    public function generateQuestions(int $quantity = 5, ?string $topic = null): array
    {
        $quantity = max(1, min(50, $quantity));

        // 1. Primary: Generate using the official Laravel AI SDK Agent
        try {
            // Create a pool of varied topics and shuffle them to ensure randomness per request
            $categories = [
                'weird marine life',
                'bizarre historical events',
                'unusual culinary traditions',
                'deep space oddities',
                'human anatomy quirks',
                'ancient civilizations',
                'obsolete technology',
                'strange global laws',
                'mythology',
                'geography facts',
                'rare insects',
                'forgotten inventions',
                'extreme weather phenomena',
            ];

            shuffle($categories);
            $randomTopics = implode(', ', array_slice($categories, 0, 4));

            // Generate a dynamic seed to force the LLM to skip its cache and think differently
            $randomSeed = time().'-'.uniqid();

            $agent = new LivestreamQuizAgent;

            // Construct a prompt that forces extreme variety
            $prompt = "Generate exactly {$quantity} very random, wildly unpredictable trivia statements. "
                ."Focus specifically on these random topics: {$randomTopics}. "
                .'Ensure they are completely different from common trivia facts. '
                .'Unpredictably mix true and false answers. '
                ."Do NOT prefix statements with 'True or False:' or 'True/False:'. "
                .'State each fact directly as a simple statement. '
                ."(Randomization Seed: {$randomSeed})";

            $response = $agent->prompt($prompt);

            if (isset($response['questions']) && is_array($response['questions'])) {
                $normalized = $this->normalizeQuestions($response['questions'], $quantity);
                if (! empty($normalized)) {
                    return $normalized;
                }
            }
        } catch (Throwable $e) {
            Log::warning('Laravel AI SDK Question generation fallback: '.$e->getMessage());
        }

        // 2. Fallback: Curated trivia questions bank (when AI API keys are not configured or on network timeout)
        return $this->getFallbackQuestions($quantity, $topic);
    }

    /**
     * Normalize and sanitize question list.
     *
     * @param  array<mixed>  $rawQuestions
     * @return array<int, array{question: string, answer: string}>
     */
    public function normalizeQuestions(array $rawQuestions, int $limit = 50): array
    {
        $normalized = [];

        foreach ($rawQuestions as $item) {
            if (! is_array($item)) {
                continue;
            }

            $questionText = trim((string) ($item['question'] ?? ''));
            $rawAnswer = strtolower(trim((string) ($item['answer'] ?? '')));

            if ($questionText === '') {
                continue;
            }

            // Strip any leading "True or False:", "True/False:", "T/F:", "Is it true that " prefixes
            $questionText = preg_replace('/^(true\s*(or|\/)\s*false|t\/f)\s*[:\-–—]?\s*/i', '', $questionText);
            $questionText = preg_replace('/^(is it true that\s*)/i', '', $questionText);
            $questionText = ucfirst(trim((string) $questionText));

            $answer = ($rawAnswer === 'true' || $rawAnswer === '1' || $rawAnswer === 'yes') ? 'true' : 'false';

            $normalized[] = [
                'question' => $questionText,
                'answer' => $answer,
            ];

            if (count($normalized) >= $limit) {
                break;
            }
        }

        return $normalized;
    }

    /**
     * Curated fallback question pool for instant response without API dependencies.
     *
     * @return array<int, array{question: string, answer: string}>
     */
    private function getFallbackQuestions(int $quantity, ?string $topic = null): array
    {
        $pool = [
            ['question' => 'Bananas are naturally slightly radioactive.', 'answer' => 'true'],
            ['question' => 'Oxford University is older than the Aztec Empire.', 'answer' => 'true'],
            ['question' => 'Wombat poop is shaped like a cube.', 'answer' => 'true'],
            ['question' => 'Strawberries are not actually botanical berries.', 'answer' => 'true'],
            ['question' => 'Watermelons and bananas are classified botanically as berries.', 'answer' => 'true'],
            ['question' => 'Sharks existed on Earth before trees.', 'answer' => 'true'],
            ['question' => 'Sloths can take up to a month to digest a single leaf.', 'answer' => 'true'],
            ['question' => 'The national animal of Scotland is the Unicorn.', 'answer' => 'true'],
            ['question' => 'A day on Venus is longer than a year on Venus.', 'answer' => 'true'],
            ['question' => 'Cleopatra lived closer in time to the Moon landing than to the construction of the Great Pyramid of Giza.', 'answer' => 'true'],
            ['question' => 'Nintendo was founded as a playing card company in the year 1889.', 'answer' => 'true'],
            ['question' => 'Octopuses have three hearts and blue blood.', 'answer' => 'true'],
            ['question' => 'Hot water freezes faster than cold water under certain thermodynamic conditions.', 'answer' => 'true'],
            ['question' => 'Honey never spoils when kept sealed.', 'answer' => 'true'],
            ['question' => 'Elephants are the only mammals that cannot jump.', 'answer' => 'true'],
            ['question' => 'A jiffy is an actual scientific unit of time.', 'answer' => 'true'],
            ['question' => 'Avocados are toxic to birds.', 'answer' => 'true'],
            ['question' => 'Cashews grow attached to the bottom of an apple-like fruit.', 'answer' => 'true'],
            ['question' => 'Humans and giraffes have the exact same number of neck vertebrae (7).', 'answer' => 'true'],
            ['question' => 'Flamingos are born bright pink.', 'answer' => 'false'],
            ['question' => 'Goldfish only have a three-second memory.', 'answer' => 'false'],
            ['question' => 'Chameleons change color exclusively to camouflage with their surroundings.', 'answer' => 'false'],
            ['question' => 'Water boils at 50 degrees Celsius at standard sea level.', 'answer' => 'false'],
            ['question' => 'The Great Wall of China can be seen from the Moon with the naked human eye.', 'answer' => 'false'],
            ['question' => 'Bulls get enraged specifically by the color red.', 'answer' => 'false'],
            ['question' => 'Humans only use 10 percent of their brains.', 'answer' => 'false'],
            ['question' => 'Lightning never strikes the same location twice.', 'answer' => 'false'],
            ['question' => 'Spiders are classified as insects.', 'answer' => 'false'],
            ['question' => 'Ostriches bury their heads in the sand when they are frightened.', 'answer' => 'false'],
            ['question' => 'Eating carrots gives humans superhuman night vision.', 'answer' => 'false'],
            ['question' => 'Sharks are mammals.', 'answer' => 'false'],
            ['question' => 'Mars is the hottest planet in our solar system.', 'answer' => 'false'],
            ['question' => 'The capital of Australia is Sydney.', 'answer' => 'false'],
            ['question' => 'Tomatoes were once feared in Europe and believed to be poisonous.', 'answer' => 'true'],
            ['question' => 'Peanuts are nuts that grow on tall trees.', 'answer' => 'false'],
            ['question' => 'Koalas have unique fingerprints that are nearly indistinguishable from human fingerprints.', 'answer' => 'true'],
        ];

        shuffle($pool);

        return array_slice($pool, 0, min($quantity, count($pool)));
    }
}
