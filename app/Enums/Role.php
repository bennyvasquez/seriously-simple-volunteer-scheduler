<?php

namespace App\Enums;

/**
 * Roles beyond being a volunteer (spec section 6). Everyone with an account is a volunteer.
 */
enum Role: string
{
    case Admin = 'admin';
    case Coordinator = 'coordinator';
    case AreaLead = 'area_lead';
}
