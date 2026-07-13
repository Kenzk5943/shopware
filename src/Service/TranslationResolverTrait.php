<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Service;

trait TranslationResolverTrait
{
    /**
     * Resolve a translated string field for a given language, with fallback to default context.
     */
    private function getTranslatedString(object $entity, string $field, string $languageId): string
    {
        if ($languageId !== '' && method_exists($entity, 'getTranslations')) {
            $translations = $entity->getTranslations();
            if ($translations !== null) {
                foreach ($translations as $translation) {
                    if (method_exists($translation, 'getLanguageId') && $translation->getLanguageId() === $languageId) {
                        $getter = 'get' . ucfirst($field);
                        if (method_exists($translation, $getter)) {
                            $value = $translation->$getter();
                            if (\is_string($value) && $value !== '') {
                                return $value;
                            }
                        }
                    }
                }
            }
        }

        if (method_exists($entity, 'getTranslation')) {
            $value = $entity->getTranslation($field);
            if (\is_string($value) && $value !== '') {
                return $value;
            }
        }

        $getter = 'get' . ucfirst($field);
        if (method_exists($entity, $getter)) {
            $value = $entity->$getter();
            return \is_string($value) ? $value : '';
        }

        return '';
    }
}
