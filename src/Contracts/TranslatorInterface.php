<?php

namespace EduLazaro\Laratext\Contracts;

interface TranslatorInterface
{
    public function translate(string $text, string $from, array $to): array;
}
