<?php

namespace App\Ai\Agents;

use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Attributes\UseSmartestModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

#[Timeout(120)]
#[UseSmartestModel]
class ImageTransformerAgent implements Agent
{
    use Promptable;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return 'You are an expert AI image prompt engineer for e-commerce. Your task is to analyze the provided product image and create a highly descriptive, professional text prompt to be used by an AI image generator. The goal is to place the exact same product in a beautifully lit, aesthetic, and high-quality background environment. CRITICAL REQUIREMENT: Your output prompt MUST explicitly instruct the image generator that the main product/subject itself must remain EXACTLY identical, unmodified, and untouched. Focus only on describing the background, lighting, and composition. Your output should be exactly and ONLY the prompt text itself.';
    }
}
