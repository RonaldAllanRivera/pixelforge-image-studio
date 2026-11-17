<?php

namespace App\Reports;

use App\Pixelforge\UsageFigures;
use App\Support\Nanodollars;

/**
 * Our records for a day against Pixelforge's GET /v1/usage for the same day. They match when
 * the number of generations, all four token counts and the cost are equal, to the nanodollar.
 */
final class UsageComparison
{
    /**
     * What differs, in plain words, in a fixed order. Empty when they match.
     *
     * @return list<string>
     */
    public static function differences(UsageFigures $ours, UsageFigures $theirs): array
    {
        $counts = [
            'Generations' => [$ours->generations, $theirs->generations],
            'Text tokens' => [$ours->tokens->inputText, $theirs->tokens->inputText],
            'Image tokens' => [$ours->tokens->inputImage, $theirs->tokens->inputImage],
            'Output tokens' => [$ours->tokens->output, $theirs->tokens->output],
            'Reasoning tokens' => [$ours->tokens->reasoning, $theirs->tokens->reasoning],
        ];

        $differences = [];

        foreach ($counts as $label => [$our, $their]) {
            if ($our !== $their) {
                $differences[] = "{$label}: ours ".number_format($our).', Pixelforge '.number_format($their);
            }
        }

        if ($ours->costNano !== $theirs->costNano) {
            $differences[] = 'Cost: ours '.Nanodollars::format($ours->costNano, 9).', Pixelforge '.Nanodollars::format($theirs->costNano, 9);
        }

        return $differences;
    }
}
