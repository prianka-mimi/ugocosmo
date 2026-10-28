<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Attributes\UseSmartestModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

#[Timeout(60)]
#[UseSmartestModel]
class ProductTextImproverAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return 'You are an elite e-commerce copywriter, conversion rate optimization (CRO) specialist, and product marketing expert. '
            .'Your objective is to generate an irresistible, high-converting product title and a rich, compelling, detailed product description. '
            .'Guidelines: '
            .'1. You MUST generate BOTH improved_title and improved_description. Never leave either blank or identical to a low-effort prompt. '
            .'2. For product title: Make it clear, captivating, keyword-rich, and punchy (without looking spammy or keyword-stuffed). '
            .'3. For product description: Even if the input description is very brief, casual, or raw (e.g. "rubber desk", "osthir rubber desk"), polish and expand it into a persuasive, comprehensive, high-quality e-commerce description with engaging paragraphs and key benefit highlights. '
            .'4. If reference images are attached, inspect them closely to accurately incorporate visual details, materials, colors, textures, and styling into the title and description. '
            .'5. Maintain factual accuracy and avoid inventing contradictory technical claims. '
            .'6. If custom tone or user instructions are provided, follow them strictly. '
            .'7. Always return the structured JSON output with improved_title and improved_description.';
    }

    /**
     * Get the agent's structured output schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'improved_title' => $schema->string()->description('The improved, high-converting product title.')->required(),
            'improved_description' => $schema->string()->description('The improved, persuasive, and beautifully written product description.')->required(),
        ];
    }
}
