<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Cuerpo multipart/form-data en memoria. PHP solo lo interpreta en POST; para PUT (como la carga del
 * CSD) se lee el cuerpo crudo. Los archivos nunca se escriben en disco desde aquí ni se registran.
 */
final class Multipart
{
    /**
     * @param  list<string>  $fileFields  campos de archivo requeridos
     * @param  list<string>  $textFields  campos de texto requeridos
     * @return array<string, string> contenido de cada campo
     */
    public static function read(Request $request, array $fileFields, array $textFields, int $maxFileBytes, int $maxTextLength): array
    {
        [$texts, $files] = $request->files->count() || $request->isMethod('POST')
            ? [$request->request->all(), array_map(fn ($f) => $f instanceof UploadedFile ? (string) file_get_contents($f->getRealPath()) : null, $request->files->all())]
            : self::parse($request);

        $errors = [];
        foreach (array_diff(array_keys($texts + $files), [...$fileFields, ...$textFields]) as $extra) {
            $errors[$extra] = 'Campo no permitido.';
        }
        $out = [];
        foreach ($fileFields as $field) {
            $content = $files[$field] ?? null;
            if (! is_string($content) || $content === '') {
                $errors[$field] = 'El archivo es obligatorio.';
            } elseif (strlen($content) > $maxFileBytes) {
                $errors[$field] = "El archivo no debe pasar de $maxFileBytes bytes.";
            } else {
                $out[$field] = $content;
            }
        }
        foreach ($textFields as $field) {
            $value = $texts[$field] ?? null;
            if (! is_string($value) || $value === '') {
                $errors[$field] = 'El campo es obligatorio.';
            } elseif (mb_strlen($value) > $maxTextLength) {
                $errors[$field] = "No debe pasar de $maxTextLength caracteres.";
            } else {
                $out[$field] = $value;
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }

    /** @return array{0: array<string, string>, 1: array<string, string>} campos de texto y de archivo */
    private static function parse(Request $request): array
    {
        if (! preg_match('/boundary="?([^";]+)"?/i', (string) $request->header('Content-Type'), $m)) {
            throw ValidationException::withMessages(['body' => 'Se espera multipart/form-data.']);
        }
        $texts = [];
        $files = [];
        foreach (explode('--'.$m[1], (string) $request->getContent()) as $part) {
            $part = preg_replace('/^\r\n/', '', $part);
            if ($part === '' || str_starts_with($part, '--') || ! str_contains($part, "\r\n\r\n")) {
                continue;
            }
            [$head, $body] = explode("\r\n\r\n", $part, 2);
            $body = preg_replace('/\r\n$/', '', $body);
            if (! preg_match('/name="([^"]*)"/i', $head, $name)) {
                continue;
            }
            if (preg_match('/filename="/i', $head)) {
                $files[$name[1]] = $body;
            } else {
                $texts[$name[1]] = $body;
            }
        }

        return [$texts, $files];
    }
}
