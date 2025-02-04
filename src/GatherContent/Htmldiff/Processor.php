<?php

namespace GatherContent\Htmldiff;

class Processor implements ProcessorInterface
{
    private array $openedTags = [];

    public function prepareHtmlInput(string $html): string
    {
        $config = array(
            'wrap' => false,
            'show-body-only' => true,
        );

        $tidyNode = tidy_parse_string($html, $config, 'utf8')->body();

        $htmlArray = $this->toArray($tidyNode);

        $html = implode("\n", $htmlArray);

        return $html;
    }

    private function toArray(\tidyNode $tidyNode, $prefix = '')
    {
        $result = array();

        if (trim($tidyNode->name) !== '') {

            $attributesString = '';

            if (is_array($tidyNode->attribute) && count($tidyNode->attribute) > 0) {

                foreach ($tidyNode->attribute as $name => $value) {

                    $attributesString .= ' '.$name.'="'.$value.'"';
                }
            }

            $prefix .= '<'.$tidyNode->name.$attributesString.'>';

            $result[] = $prefix.'start';

            if ($tidyNode->hasChildren()) {

                foreach ($tidyNode->child as $childNode) {

                    $tokenized = $this->toArray($childNode, $prefix);

                    $result = array_merge($result, $tokenized);
                }
            }

            $result[] = $prefix.'end';

        } else {

            if (trim($tidyNode->value) !== '') {

                $words = explode(' ', trim($tidyNode->value));

                foreach ($words as $word) {

                    if ($word !== '') {
                        $result[] = $prefix.'"'.$word.'"';
                    }
                }
            }
        }

        return $result;
    }

    public function prepareHtmlOutput(string $diff): string
    {
        $html = '';

        $lines = explode("\n", $diff);

        foreach ($lines as $line) {

            $result = preg_match('/([+\-\s])(<.*>)(.*)/', $line, $lineParts);

            if ($result == 1) {

                $type = $lineParts[1];
                $path = $lineParts[2];
                $leaf = $lineParts[3];

                if ($leaf == 'start') {

                    $html .= $this->openTag($path);

                } elseif ($leaf == 'end') {

                    $html .= $this->closeTag($path);

                } else {

                    $html .= $this->prepareLeaf($leaf, $type);
                }

                $this->tagsHistory($path, $leaf);
            } else {

                $html .= $this->fixMissingTag($line);
            }
        }

        $html = $this->cleanup($html);

        return $html;
    }

    private function openTag($path)
    {
        preg_match_all('/<[^>]*>/', $path, $tags);

        return end($tags[0]);
    }

    private function closeTag($path)
    {
        $openTag = $this->openTag($path);

        preg_match("/<([^\s>]*)/", $openTag, $output);

        $tagName = $output[1];

        return '</'.$tagName.'> ';
    }

    private function prepareLeaf($leaf, $type)
    {
        $realLeaf = substr($leaf, 1, -1);

        if ($type == '+') {

            return '<ins>'.$realLeaf.'</ins> ';

        } elseif ($type == '-') {

            return '<del>'.$realLeaf.'</del> ';

        } else {

            return $realLeaf.' ';

        }
    }

    private function tagsHistory($path, $leaf)
    {
        $openTag = $this->openTag($path);

        if (!$this->isSingletonTag($openTag)) {
            if ($leaf == 'start') {
                $this->openedTags[] = $openTag;
            }
            if ($leaf == 'end') {
                array_pop($this->openedTags);
            }
        }
    }

    public function isSingletonTag($tag)
    {
        $singletonTags = [
            '<area',
            '<base',
            '<br',
            '<col',
            '<command',
            '<embed',
            '<hr',
            '<img',
            '<input',
            '<link',
            '<meta',
            '<param',
            '<source',
        ];

        $isSingletonTag = false;

        foreach ($singletonTags as $singletonTag) {
            if ($tag && str_contains($tag, $singletonTag)) {
                $isSingletonTag = true;
            }
        }

        return $isSingletonTag;
    }

    /**
     * Insert missing tag if preg_match was not working.
     * TODO: Add more tests to find the same for opening tag.
     *
     * @return string
     */
    private function fixMissingTag(): string
    {
        if (count($this->openedTags)) {
            $tag = array_pop($this->openedTags);
            return $this->closeTag($tag);
        }

        return '';
    }

    private function cleanup($html)
    {
        $html = str_replace(' </', '</', $html);
        $html = str_replace('> <', '><', $html);

        $html = str_replace(array('</ins><ins>', '</del><del>'), ' ', $html);
        $html = str_replace(array('<body>', '</body>', '<ins></ins>', '<del></del>'), '', $html);

        $singletonClosingTags = array(
            '</area>',
            '</base>',
            '</br>',
            '</col>',
            '</command>',
            '</embed>',
            '</hr>',
            '</img>',
            '</input>',
            '</link>',
            '</meta>',
            '</param>',
            '</source>'
        );

        $html = str_replace($singletonClosingTags, '', $html);

        return trim($html);
    }
}