<?php

namespace GatherContent\Htmldiff;

class HtmlProcessor implements ProcessorInterface
{
    private array $tags = [];

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
        $prevPath = '';
        $openType = '';

        $lines = explode("\n", $diff);

        foreach ($lines as $line) {
            $result = preg_match('/([+\-\s])(<.*>)(.*)/', $line, $lineParts);

            if ($result == 1) {
                $type = $lineParts[1];  // "+" / "-"
                $path = $lineParts[2];  // html string
                $leaf = $lineParts[3];  // "start" / "end" / content

                // Check if another diff tag started
                if ($prevType != $type) {
                    // Check if we need to close previous diff tag
                    if ($prevType != '') {
                        $html .= $this->closeDiffTag($prevType);
                        $openType = '';
                    }
    
                    $html .= $this->openDiffTag($type);
    
                    $prevType = $openType = $type;
                }
    
                $html .= match ($leaf) {
                    'start' => $this->openTag($path),
                    'end' => $this->closeTag($path),
                    default => $this->insertLeaf($leaf)
                };
    
                $prevPath = $path;
                
                $this->tagsHistory($prevPath, $leaf, $type);
            } else {
                $html .= $this->fixMissingTag($line);
            }
        }

        // Close opened diff tag if there is one.
        if ($openType != '') {
            $html .= $this->closeDiffTag($openType);
        }

        $html = $this->cleanup($html);

        return $html;
    }

    private function tagsHistory($path, $leaf, $type)
    {
        if ($leaf == 'start') {
            $this->tags[] = $this->openTag($path);
        }
        if ($leaf == 'end') {
            array_pop($this->tags);
        }
    }

    /**
     * Insert missing tag if preg_match was not working.
     * TODO: Add more tests to find the same for opening tag.
     *
     * @return string
     */
    private function fixMissingTag(): string
    {
        if (end($this->tags) != '<body>') {
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

        $tagName = $output[1];

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