<?php

namespace App\PublicSite;

/** Lo que contestó el modelo: texto y, si usó una herramienta, su nombre y entrada. */
final class AssistantReply
{
    /** @param array<string, mixed>|null $toolInput */
    public function __construct(
        public readonly string $text,
        public readonly ?string $tool = null,
        public readonly ?array $toolInput = null,
    ) {}
}
