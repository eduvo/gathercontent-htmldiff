<?php

namespace GatherContent\Htmldiff;

class HtmlProcessor implements ProcessorInterface
{
    private array $tags = [];
    private array $openedTags = [];

    /**
     * Parse and prepare a document stored in a string.
     *
     * @param string $html
     * @return string
     */
    public function prepareHtmlInput(string $html): string
    {
        $config = [
            'wrap' => false,
            'show-body-only' => true,
        ];

        $tidyNode = tidy_parse_string($html, $config, 'utf8')->body();

        $htmlArray = $this->toArray($tidyNode);

        return implode("\n", $htmlArray);
    }

    private function toArray(\tidyNode $tidyNode, $prefix = '')
    {
        $result = [];

        if (trim($tidyNode->name) !== '') {
            $attributesString = '';

            if (is_array($tidyNode->attribute) && count($tidyNode->attribute) > 0) {
                foreach ($tidyNode->attribute as $name => $value) {
                    $attributesString .= ' ' . $name . '="' . $value . '"';
                }
            }

            $prefix .= '<' . $tidyNode->name.$attributesString . '>';

            $result[] = $prefix . 'start';

            if ($tidyNode->hasChildren()) {
                foreach ($tidyNode->child as $childNode) {
                    $tokenized = $this->toArray($childNode, $prefix);
                    $result = array_merge($result, $tokenized);
                }
            }

            $result[] = $prefix . 'end';
        } else {
            if (trim($tidyNode->value) !== '') {
                $words = explode(' ', trim($tidyNode->value));

                foreach ($words as $word) {
                    if ($word !== '') {
                        $result[] = $prefix . '"' . $word . '"';
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Parse and prepare string from the difference stored in a string.
     *
     * @param string $diff
     * @return string
     */
    public function prepareHtmlOutput(string $diff): string
    {
        $html = '';
        $prevType = '';
        $openType = '';

        $lines = explode("\n", $diff);

        foreach ($lines as $line) {
            $result = preg_match('/([+\-\s])(<.*>)(.*)/', $line, $lineParts);

            if ($result == 1) {
                $type = $lineParts[1];  // "+" / "-"
                $path = $lineParts[2];  // html string
                $leaf = $lineParts[3];  // "start" / "end" / content

                if ($prevType != $type) {
                    if (trim($prevType) != '') {
                        $html .= $this->closeDiffTag($prevType);
                    }

                    $html .= $this->openDiffTag($type);
                    $this->openedTags = [];
                    $openType = $type;
                }

                if ($leaf == 'start') {
                    $openTag = $this->openTag($path);

                    $html .= $openTag;

                    if (trim($prevType) != '' && trim($openType) != '') {
                        $this->openedTags[] = htmlspecialchars($openTag);
                    }
                } elseif ($leaf == 'end') {
                    if (trim($openType) != '' && count($this->openedTags) == 0) {
                        $html .= $this->closeDiffTag($type);
                    }

                    $closeTag = $this->closeTag($path);

                    $html .= $closeTag;

                    if (trim($openType) != '' && count($this->openedTags) > 0) {
                        array_pop($this->openedTags);
                    } else {
                        $html .= $this->openDiffTag($type);
                    }
                } else {
                    $html .= $this->insertLeaf($leaf);
                }

                $prevType = $type;
                $prevPath = $path;
                
                $this->tagsHistory($prevPath, $leaf, $type);
            } else {
                $html .= $this->fixMissingTag($line);
                array_pop($this->tags);
            }
        }

        // Close opened diff tag if there is one.
        if (trim($openType) != '') {
            $html .= $this->closeDiffTag($openType);
            $openType = '';
        }

        $html = $this->cleanup($html);

        return $html;
    }

    private function tagsHistory($path, $leaf, $type)
    {
        $openTag = $this->openTag($path);

        if (!$this->isSingletonTag($openTag)) {
            if ($leaf == 'start') {
                $this->tags[] = $openTag;
            }
            if ($leaf == 'end') {
                array_pop($this->tags);
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
        if (count($this->tags)) {
            return $this->closeTag(end($this->tags));
        }

        return '';
    }

    /**
     * Insert opening diff tag.
     *
     * @param string $type
     * @return string
     */
    private function openDiffTag(string $type): string
    {
        return match ($type) {
            '+' => '<ins>',
            '-' => '<del>',
            default => ''
        };
    }

    /**
     * Insert closing diff tag.
     *
     * @param string $type
     * @return string
     */
    private function closeDiffTag(string $type): string
    {
        return match ($type) {
            '+' => '</ins>',
            '-' => '</del>',
            default => ''
        };
    }

    /**
     * Insert opening tag.
     *
     * @param string $path
     * @return mixed
     */
    public function openTag(string $path): mixed
    {
        preg_match_all('/<[^>]*>/', $path, $tags);

        return end($tags[0]);
    }

    /**
     * Insert closing tag.
     *
     * @param string $path
     * @return string
     */
    public function closeTag(string $path): string
    {
        $openTag = $this->openTag($path);

        preg_match("/<([^\s>]*)/", $openTag, $output);

        $tagName = end($output);

        return '</'.$tagName.'> ';
    }

    /**
     * Insert leaf without surrounded quotes.
     *
     * @param string $leaf
     * @return string
     */
    private function insertLeaf(string $leaf): string
    {
        if ($this->isSingletonTag($leaf)) {
            return '';
        }

        $realLeaf = substr($leaf, 1, -1);

        return $realLeaf . ' ';
    }

    /**
     * Cleanup
     *
     * @param string $html
     * @return string
     */
    private function cleanup(string $html): string
    {
        $html = str_replace(' <', '<', $html);

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