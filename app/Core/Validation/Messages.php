<?php

namespace App\Core\Validation;

/**
 * The default wording for each rule.
 *
 * Kept in one place so a site can translate the lot without touching the
 * validator, and so a rule and its message cannot drift apart in two files.
 *
 * Placeholders: :field, :min, :max, :value, :values.
 */
final class Messages {
    /** @var array<string, string> */
    private static array $messages = [
        'required' => 'The :field field is required.',
        'string' => 'The :field must be text.',
        'int' => 'The :field must be a whole number.',
        'integer' => 'The :field must be a whole number.',
        'numeric' => 'The :field must be a number.',
        'bool' => 'The :field must be true or false.',
        'boolean' => 'The :field must be true or false.',
        'array' => 'The :field must be a list.',
        'email' => 'The :field must be a valid email address.',
        'url' => 'The :field must be a valid URL.',
        'date' => 'The :field must be a valid date.',
        'alpha' => 'The :field may only contain letters.',
        'alpha_num' => 'The :field may only contain letters and numbers.',
        'alpha_dash' => 'The :field may only contain letters, numbers, dashes and underscores.',
        'min' => 'The :field must be at least :min.',
        'min.string' => 'The :field must be at least :min characters.',
        'min.array' => 'The :field must have at least :min items.',
        'max' => 'The :field may not be greater than :max.',
        'max.string' => 'The :field may not be longer than :max characters.',
        'max.array' => 'The :field may not have more than :max items.',
        'between' => 'The :field must be between :min and :max.',
        'between.string' => 'The :field must be between :min and :max characters.',
        'between.array' => 'The :field must have between :min and :max items.',
        'in' => 'The :field must be one of: :values.',
        'not_in' => 'The :field may not be one of: :values.',
        'regex' => 'The :field is not in the expected format.',
        'same' => 'The :field must match the :value.',
        'different' => 'The :field must differ from the :value.',
        'confirmed' => 'The :field does not match its confirmation.',
        'accepted' => 'The :field must be accepted.',
        'closure' => 'The :field is not valid.',
    ];

    /**
     * The wording for a rule, optionally for a kind of value.
     *
     * "must be at least 8" is right for a number and wrong for a password;
     * $type picks the variant, and falls back to the plain one where a rule has
     * nothing type-specific to say.
     */
    public static function for(string $rule, string $type = ''): string {
        if ($type !== '' && isset(self::$messages[$rule . '.' . $type])) {
            return self::$messages[$rule . '.' . $type];
        }

        return self::$messages[$rule] ?? 'The :field is not valid.';
    }

    /**
     * Replaces the wording, wholly or in part - a translation, or one message
     * a site words differently.
     *
     * @param array<string, string> $messages
     */
    public static function override(array $messages): void {
        self::$messages = $messages + self::$messages;
    }
}
