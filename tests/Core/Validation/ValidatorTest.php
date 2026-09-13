<?php

declare(strict_types=1);

namespace Tests\Core\Validation;

use App\Core\Validation\ErrorBag;
use App\Core\Validation\ValidationException;
use App\Core\Validation\Validator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(Validator::class)]
#[CoversClass(ErrorBag::class)]
final class ValidatorTest extends TestCase {
    // -----------------------------------------------------------------
    // The shape of a run
    // -----------------------------------------------------------------

    public function testItPassesValidData(): void {
        $validator = Validator::make(['email' => 'a@b.cz'], ['email' => 'required|email']);

        self::assertTrue($validator->passes());
        self::assertSame(['email' => 'a@b.cz'], $validator->validate());
    }

    #[TestDox('validate() returns only the fields that were asked for')]
    public function testValidatedDropsEverythingElse(): void {
        $data = Validator::make(
            ['name' => 'Jan', 'is_admin' => '1', 'csrf_token' => 'x'],
            ['name' => 'required'],
        )->validate();

        // Handing the whole POST to a repository is how mass-assignment bugs
        // happen; returning only what was asked for is the point.
        self::assertSame(['name' => 'Jan'], $data);
    }

    public function testFailureThrowsWithTheErrors(): void {
        try {
            Validator::make([], ['name' => 'required'])->validate();
            self::fail('expected a ValidationException');
        } catch (ValidationException $e) {
            self::assertTrue($e->errors()->has('name'));
            self::assertSame('The name field is required.', $e->errors()->first('name'));
        }
    }

    #[TestDox('only the first failure of a field is reported')]
    public function testOneMessagePerField(): void {
        // "required" and "not an email" about the same empty box helps nobody.
        $errors = Validator::make(['email' => ''], ['email' => 'required|email|max:5'])->errors();

        self::assertCount(1, $errors->get('email'));
        self::assertSame('The email field is required.', $errors->first('email'));
    }

    // -----------------------------------------------------------------
    // Rules
    // -----------------------------------------------------------------

    /** @return array<string, array{mixed, string, bool}> */
    public static function rules(): array {
        return [
            'required rejects empty string' => ['', 'required', false],
            'required rejects whitespace' => ['   ', 'required', false],
            'required accepts zero' => ['0', 'required', true],
            'required accepts number zero' => [0, 'required', true],
            'email accepts' => ['a@b.cz', 'email', true],
            'email rejects' => ['a@b', 'email', false],
            'int accepts numeric string' => ['42', 'int', true],
            'int rejects decimal' => ['4.2', 'int', false],
            'numeric accepts decimal' => ['4.2', 'numeric', true],
            'min on a number' => [5, 'numeric|min:10', false],
            'min on a string counts characters' => ['abc', 'min:5', false],
            'max on a string counts characters' => ['abcdef', 'max:3', false],
            'between on a number' => ['19', 'int|between:18,120', true],
            'between rejects below' => ['17', 'int|between:18,120', false],
            'in accepts' => ['admin', 'in:admin,editor', true],
            'in rejects' => ['root', 'in:admin,editor', false],
            'not_in rejects' => ['root', 'not_in:root', false],
            'url accepts' => ['https://example.org', 'url', true],
            'alpha_dash accepts a slug' => ['a-b_c1', 'alpha_dash', true],
            'alpha_dash rejects a space' => ['a b', 'alpha_dash', false],
            'accepted accepts on' => ['on', 'accepted', true],
            'accepted rejects absence' => [null, 'accepted', false],
            'array counts items' => [[1, 2], 'array|min:3', false],
        ];
    }

    #[DataProvider('rules')]
    public function testRule(mixed $value, string $rule, bool $expected): void {
        $validator = Validator::make(['field' => $value], ['field' => $rule]);

        self::assertSame($expected, $validator->passes(), $validator->errors()->first('field'));
    }

    #[TestDox('a string of digits is measured by its length unless the field is numeric')]
    public function testDigitStringsAreMeasuredByLength(): void {
        // A phone number or a PIN is text that happens to be digits.
        self::assertTrue(Validator::make(['phone' => '777123456'], ['phone' => 'required|max:20'])->passes());
        self::assertTrue(Validator::make(['pin' => '00001234'], ['pin' => 'required|min:8|max:8'])->passes());
        self::assertSame(
            'The pin must be at least 8 characters.',
            Validator::make(['pin' => '1234'], ['pin' => 'min:8'])->errors()->first('pin'),
        );

        // With a numeric rule, the value is what counts.
        self::assertFalse(Validator::make(['age' => '9'], ['age' => 'numeric|min:18'])->passes());
        self::assertTrue(Validator::make(['qty' => '120'], ['qty' => 'int|max:500'])->passes());
    }

    #[TestDox('an optional field that was not sent is left out of the result')]
    public function testAbsentNullableFieldIsNotReturned(): void {
        // Returned as null it would be written as null by an update.
        $data = Validator::make(['name' => 'Jan'], ['name' => 'required', 'website' => 'nullable|url'])->validate();

        self::assertSame(['name' => 'Jan'], $data);
    }

    #[TestDox('zero is a value, not an absence')]
    public function testZeroIsNotEmpty(): void {
        // The classic bug: a required quantity of 0 read as "you forgot this".
        self::assertTrue(Validator::make(['qty' => 0], ['qty' => 'required'])->passes());
        self::assertTrue(Validator::make(['qty' => '0'], ['qty' => 'required'])->passes());
    }

    public function testConfirmedComparesAgainstTheConfirmationField(): void {
        self::assertTrue(Validator::make(
            ['password' => 'secret12', 'password_confirmation' => 'secret12'],
            ['password' => 'confirmed'],
        )->passes());

        self::assertFalse(Validator::make(
            ['password' => 'secret12', 'password_confirmation' => 'other'],
            ['password' => 'confirmed'],
        )->passes());
    }

    public function testSameAndDifferentCompareTwoFields(): void {
        self::assertTrue(Validator::make(['a' => 'x', 'b' => 'x'], ['a' => 'same:b'])->passes());
        self::assertTrue(Validator::make(['a' => 'x', 'b' => 'y'], ['a' => 'different:b'])->passes());
    }

    #[TestDox('regex arguments survive the parser, colons and all')]
    public function testRegexRule(): void {
        // Split on the first colon only, or a pattern with one in it breaks.
        $rules = ['code' => ['regex:/^[A-Z]{2}:\d+$/']];

        self::assertTrue(Validator::make(['code' => 'AB:12'], $rules)->passes());
        self::assertFalse(Validator::make(['code' => 'ab-12'], $rules)->passes());
    }

    // -----------------------------------------------------------------
    // Modifiers
    // -----------------------------------------------------------------

    #[TestDox('nullable lets an empty optional field through')]
    public function testNullable(): void {
        // An untouched optional input posts '', which `email` would reject.
        $validator = Validator::make(['website' => ''], ['website' => 'nullable|url']);

        self::assertTrue($validator->passes());
        self::assertSame(['website' => ''], $validator->validated());
    }

    #[TestDox('nullable still validates a value that was given')]
    public function testNullableDoesNotSkipRealValues(): void {
        self::assertFalse(Validator::make(['website' => 'nonsense'], ['website' => 'nullable|url'])->passes());
    }

    #[TestDox('sometimes skips a field that was not sent at all')]
    public function testSometimes(): void {
        // What a PATCH needs: absent means "not changing", not "cleared".
        self::assertTrue(Validator::make([], ['name' => 'sometimes|required'])->passes());
        self::assertFalse(Validator::make(['name' => ''], ['name' => 'sometimes|required'])->passes());
    }

    // -----------------------------------------------------------------
    // Messages
    // -----------------------------------------------------------------

    #[TestDox('size messages read correctly for strings, arrays and numbers')]
    public function testSizeMessagesDependOnTheValue(): void {
        self::assertSame(
            'The password must be at least 8 characters.',
            Validator::make(['password' => 'short'], ['password' => 'min:8'])->errors()->first('password'),
        );

        self::assertSame(
            'The tags must have at least 3 items.',
            Validator::make(['tags' => ['a']], ['tags' => 'array|min:3'])->errors()->first('tags'),
        );

        self::assertSame(
            'The age must be at least 18.',
            Validator::make(['age' => '9'], ['age' => 'numeric|min:18'])->errors()->first('age'),
        );
    }

    public function testCustomMessages(): void {
        $errors = Validator::make(
            ['email' => ''],
            ['email' => 'required'],
            ['email.required' => 'We need an address to reply to.'],
        )->errors();

        self::assertSame('We need an address to reply to.', $errors->first('email'));
    }

    #[TestDox('an underscored field name reads as words')]
    public function testFieldNamesAreHumanised(): void {
        $errors = Validator::make([], ['first_name' => 'required'])->errors();

        self::assertSame('The first name field is required.', $errors->first('first_name'));
    }

    // -----------------------------------------------------------------
    // Closures and misuse
    // -----------------------------------------------------------------

    #[TestDox('a closure rule may return its own message')]
    public function testClosureRules(): void {
        $rules = ['slug' => [static fn (mixed $value): string|bool => $value === 'taken'
            ? 'That slug is already in use.'
            : true]];

        self::assertTrue(Validator::make(['slug' => 'free'], $rules)->passes());
        self::assertSame(
            'That slug is already in use.',
            Validator::make(['slug' => 'taken'], $rules)->errors()->first('slug'),
        );
    }

    #[TestDox('a closure sees the whole input, not just its own field')]
    public function testClosureReceivesAllData(): void {
        $rules = ['end' => [static fn (mixed $value, array $data): bool => $value > $data['start']]];

        self::assertTrue(Validator::make(['start' => 1, 'end' => 2], $rules)->passes());
        self::assertFalse(Validator::make(['start' => 5, 'end' => 2], $rules)->passes());
    }

    #[TestDox('a rule missing its argument is refused, not defaulted')]
    public function testARuleWithoutItsArgumentThrows(): void {
        // `regex` with no pattern used to fall back to `//`, which matches
        // everything - so a typo accepted any input while looking checked.
        // A silent pass is the one outcome a validator must never produce.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The "regex" rule needs an argument');

        Validator::make(['a' => 'anything'], ['a' => 'regex'])->passes();
    }

    #[TestDox('same and different need a field to compare against')]
    public function testSameWithoutItsArgumentThrows(): void {
        $this->expectException(InvalidArgumentException::class);

        Validator::make(['a' => 'x'], ['a' => 'same'])->passes();
    }

    #[TestDox('a non-scalar is not coerced into matching an allowed value')]
    public function testInRejectsNonScalars(): void {
        // Casting an array to string yields '', which would have matched an
        // empty entry in the allowed list.
        self::assertFalse(Validator::make(['a' => ['x']], ['a' => 'in:,admin'])->passes());
        self::assertFalse(Validator::make(['a' => ['x']], ['a' => 'not_in:root'])->passes());
    }

    #[TestDox('a misspelled rule name is refused rather than silently passing')]
    public function testUnknownRuleThrows(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('There is no validation rule named "requried"');

        Validator::make(['a' => 'b'], ['a' => 'requried'])->passes();
    }

    // -----------------------------------------------------------------
    // ErrorBag
    // -----------------------------------------------------------------

    public function testErrorBag(): void {
        $bag = new ErrorBag();
        $bag->add('a', 'first');
        $bag->add('a', 'second');
        $bag->add('b', 'other');

        self::assertTrue($bag->has('a'));
        self::assertFalse($bag->has('c'));
        self::assertSame('first', $bag->first('a'));
        self::assertSame('', $bag->first('c'));
        self::assertSame(['first', 'second', 'other'], $bag->all());
        self::assertSame(['a' => 'first', 'b' => 'other'], $bag->firsts());
        self::assertCount(2, $bag);
    }

    public function testAnEmptyBagFlattensToAnEmptyArray(): void {
        self::assertSame([], (new ErrorBag())->all());
    }
}
