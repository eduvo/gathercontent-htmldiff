<?php

namespace GatherContent\Htmldiff;

class Processor implements ProcessorInterface
{
    public function prepareHtmlInput($html)
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

    public function prepareHtmlOutput($diff)
    {
        $html = '';

        // $prevType = '';
        // $prevPath = '';
        // $openType = '';

        $lines = explode("\n", $diff);

        foreach ($lines as $line) {

            $result = preg_match('/([+\-\s])(<.*>)(.*)/', $line, $lineParts);

            if ($result == 1) {

                $type = $lineParts[1];
                $path = $lineParts[2];
                $leaf = $lineParts[3];

                // if ($prevType != $type) {

                //     // If another diff tag started, we need to close previous one
                //     if ($prevType != '') {
                //         $html .= $this->endType($prevType);
                //         $openType = '';
                //     }

                //     $html .= $this->startType($type);

                //     $prevType = $openType = $type;
                // }

                if ($leaf == 'start') {

                    // $html .= $this->openTag($path);

                } elseif ($leaf == 'end') {

                    // $html .= $this->closeTag($path);

                } else {

                    $html .= $this->prepareLeaf($leaf, $type);

                    // $html .= $this->insertLeaf($leaf);
                }

                // $prevPath = $lineParts[2];
            // } else {

            //     // Fix missing closing html tags
            //     $html .= $this->closeTag($prevPath);
            }
        }

        // // We need to close opened diff tag if there is one.
        // if ($openType != '') {

        //     $html .= $this->endType($openType);
        // }

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

    private function startType($type)
    {
        if ($type == '+') {
            return '<ins>';
        } elseif ($type == '-') {
            return '<del>';
        } else {
            return '';
        }
    }

    private function endType($type)
    {
        if ($type == '+') {
            return '</ins>';
        } elseif ($type == '-') {
            return '</del>';
        } else {
            return '';
        }
    }

    /**
     * Insert leaf without surrounded quotes.
     */
    private function insertLeaf($leaf)
    {
        $realLeaf = substr($leaf, 1, -1);

        return $realLeaf . ' ';
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
