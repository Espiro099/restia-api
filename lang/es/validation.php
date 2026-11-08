<?php

// Mensajes de las reglas que usa la API. Sin este archivo, `details.fields` devolvería claves como
// `validation.required`. Agrega aquí cada regla nueva que se use en una validación.
return [
    'array' => 'Debe ser una lista.',
    'boolean' => 'Debe ser verdadero o falso.',
    'date' => 'Debe ser una fecha válida.',
    'email' => 'Debe ser un correo válido.',
    'in' => 'El valor no está permitido.',
    'integer' => 'Debe ser un número entero.',
    'max' => [
        'array' => 'Debe tener como máximo :max elementos.',
        'numeric' => 'Debe ser como máximo :max.',
        'string' => 'Debe tener como máximo :max caracteres.',
    ],
    'min' => [
        'array' => 'Debe tener al menos :min elementos.',
        'numeric' => 'Debe ser al menos :min.',
        'string' => 'Debe tener al menos :min caracteres.',
    ],
    'numeric' => 'Debe ser un número.',
    'regex' => 'El formato no es válido.',
    'required' => 'Es obligatorio.',
    'string' => 'Debe ser texto.',
    'timezone' => 'Debe ser una zona horaria válida.',
    'uuid' => 'Debe ser un UUID válido.',
];
