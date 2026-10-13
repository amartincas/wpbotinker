<?php

namespace App\Platform\Memory;

/**
 * Who a fact belongs to.
 *
 * - Person: about the customer (name, birth date...). Defined once by the
 *   Core catalog (config('botinker.person_facts')) so every domain reads the
 *   same meaning; another domain may read it only with permission.
 * - Domain: owned by one domain and never readable by any other.
 */
enum FactScope: string
{
    case Person = 'person';
    case Domain = 'domain';
}
