<?php

namespace Webkul\Admin\Services\Insurance\Contracts;

interface InsuranceDriverInterface
{
    /**
     * Verifica la cobertura de seguro del paciente.
     *
     * @param  object  $person  Modelo del paciente
     * @param  string  $ci  Cédula de identidad
     * @return array {
     *               status:  'VIGENTE' | 'EN_MORA' | 'NO_REGISTRADO' | 'SIN_SEGURO' | 'INDETERMINADO'
     *               message: string
     *               badge:   'success' | 'warning' | 'danger' | null
     *               data:    array|null
     *               success: bool
     *               }
     */
    public function verify(object $person, string $ci): array;
}
