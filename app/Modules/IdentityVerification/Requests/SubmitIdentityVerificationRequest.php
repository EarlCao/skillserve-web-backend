<?php

namespace App\Modules\IdentityVerification\Requests;

use App\Modules\IdentityVerification\Models\IdentityDocument;
use App\Shared\Helpers\AgeRequirement;
use App\Shared\Requests\BaseFormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A National ID submission. The number is validated for shape here; whether it
 * is already in use is decided by the service inside a locked transaction,
 * because that is a cross-row rule.
 */
class SubmitIdentityVerificationRequest extends BaseFormRequest
{
    /**
     * The card's shorter and longer sides must reach these in a photo of the
     * front or back, so a reviewer can read it. The app checks the same.
     */
    public const MIN_SHORT_SIDE = 500;

    public const MIN_LONG_SIDE = 800;

    protected function prepareForValidation(): void
    {
        // The app may send the number grouped; compare digits only.
        $this->merge([
            'id_number' => preg_replace('/\D/', '', (string) $this->input('id_number')) ?? '',
        ]);
    }

    public function rules(): array
    {
        $digits = (int) config('identity.card_number_digits', 16);

        return [
            'id_number' => ['required', 'string', 'digits:'.$digits],
            'full_name' => ['required', 'string', 'max:255'],
            'birthdate' => AgeRequirement::birthdayRules(),

            // Front and back of the card, plus a selfie for the reviewer to
            // match against it.
            'documents' => ['required', 'array', 'min:1', 'max:3'],
            'documents.*.type' => ['required', 'string', Rule::in(IdentityDocument::TYPES)],
            'documents.*.file' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
        ];
    }

    /**
     * A photo of the card too small to read is refused. Only photos whose size
     * can be read are judged; a PDF, or an image whose header cannot be
     * parsed, is left to the type rules and the reviewer.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach ((array) $this->input('documents', []) as $index => $document) {
                $type = is_array($document) ? ($document['type'] ?? null) : null;
                $file = $this->file("documents.{$index}.file");
                if (! in_array($type, ['id_front', 'id_back'], true) || ! $file instanceof UploadedFile) {
                    continue;
                }

                $size = @getimagesize($file->getRealPath());
                if ($size === false) {
                    continue;
                }

                [$width, $height] = $size;
                if (min($width, $height) < self::MIN_SHORT_SIDE || max($width, $height) < self::MIN_LONG_SIDE) {
                    $validator->errors()->add(
                        "documents.{$index}.file",
                        'This photo of your card is too small to read. Retake it so the card fills the frame.',
                    );
                }
            }
        }];
    }

    public function messages(): array
    {
        return [
            'id_number.digits' => 'Enter the 16 digits printed on your PhilSys card.',
            'documents.required' => 'Attach a photo of your National ID.',
            'documents.*.file.mimes' => 'Each file must be a JPG, PNG or PDF.',
            'documents.*.file.max' => 'Each file must be 10 MB or smaller.',
            ...AgeRequirement::messages('birthdate'),
        ];
    }

    public function attributes(): array
    {
        return ['id_number' => 'National ID number'];
    }
}
