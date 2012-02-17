<?php

class TypeNormalizer {
    protected $resourceFunctionRegex;

    public function __construct(array $resourceFunctionPrefixes) {
        // Generate regex like /^(ftp|socket|...)_/
        $this->resourceFunctionRegex = '/^(' . implode('|', $resourceFunctionPrefixes) . ')_/';
    }

    public function normalize($type, $function) {
        // use a general number type instead of float/int (they are usually
        // usable interchangably and we might catch some strange edge case
        // bugs through this)
        if ($type == 'float' || $type == 'int') {
            return 'number';
        }

        // use specific resource types (like ftpResource) instead of generic
        // resource type
        if ($type == 'resource') {
            if (preg_match($this->resourceFunctionRegex, $function, $matches)) {
                return $matches[1] . 'Resource';
            } else {
                // default resource is fileResource
                return 'fileResource';
            }
        }

        // leave other types untouched
        return $type;
    }
}
