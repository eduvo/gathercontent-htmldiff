<?php

namespace GatherContent\Htmldiff;

interface ProcessorInterface
{
    public function prepareHtmlInput(string $input): string;
    public function prepareHtmlOutput(string $output): string;
}
