<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Module;

use Core\Exception\UserFacingException;

/**
 * A module activation or deactivation the application *decided* to refuse,
 * as opposed to one that broke.
 *
 * {@see ModuleException} carries about fifty messages, nearly all of them
 * English manifest-validation detail — one of them interpolates an absolute
 * filesystem path — so it is deliberately not a
 * {@see UserFacingException} and its messages never reach a page. But three
 * of its throw sites are not failures at all: they are answers, written in
 * French, naming modules the admin can see in the very list they clicked
 * from.
 *
 *   « Le module « Location » nécessite le module « Finances ». Activez-le d'abord. »
 *   « Ce module est requis par le module « Rétrospectives ». Désactivez-le d'abord. »
 *
 * Substituting a generic fallback for those would take away the one thing
 * that makes them actionable — WHICH module is in the way. So they get
 * their own class, a subclass so every existing `catch (ModuleException)`
 * keeps working unchanged, and the marker rides on the subclass alone.
 *
 * Anything thrown as a plain ModuleException stays technical, stays
 * unmarked, and stays out of the UI.
 */
class ModuleRefusalException extends ModuleException implements UserFacingException
{
}
