<?php

declare(strict_types=1);

namespace App\Core\Schema\Change;

/**
 * Les seuils, par installation, au-delà desquels modifier une table mérite un avertissement (`warnAt`) puis, plus haut, de retaper
 * son nom (`confirmAt`). Le nombre de lignes est l'estimation du moteur, jamais un COUNT. 0 désactive un seuil.
 */
final readonly class BigTablePolicy
{
    public function __construct(private int $warnAt = 0, private int $confirmAt = 0)
    {
        if ($warnAt < 0 || $confirmAt < 0) {
            throw new \InvalidArgumentException('A row threshold cannot be negative.');
        }
    }

    public function assess(?int $estimatedRows): BigTableAssessment
    {
        $level = BigTableLevel::None;
        if (null !== $estimatedRows) {
            if ($this->confirmAt > 0 && $estimatedRows >= $this->confirmAt) {
                $level = BigTableLevel::ConfirmName;
            } elseif ($this->warnAt > 0 && $estimatedRows >= $this->warnAt) {
                $level = BigTableLevel::Warning;
            }
        }

        return new BigTableAssessment($level, $estimatedRows, $this->warnAt, $this->confirmAt);
    }
}
