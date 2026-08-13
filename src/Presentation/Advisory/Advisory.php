<?php

declare(strict_types=1);

namespace App\Presentation\Advisory;

final readonly class Advisory
{
    public function __construct(
        // REFACTOR-WHEN: an advisory must render in a second language, or a consumer needs to
        // branch on which advisory fired. The payload is a finished sentence, so tests and
        // consumers can only discriminate by wording; crossing the threshold means modelling
        // advisories as code + parameters — an i18n concern this layer deliberately avoids today.
        public string $message,
        public AdvisoryLevel $level,
        public ?string $advance = null,
    ) {}
}
