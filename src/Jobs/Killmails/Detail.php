<?php

/*
 * This file is part of SeAT
 *
 * Copyright (C) 2015 to 2020 Leon Jacobs
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 */

namespace Seat\Eveapi\Jobs\Killmails;

use Seat\Eveapi\Jobs\EsiBase;
use Seat\Eveapi\Models\Killmails\KillmailAttacker;
use Seat\Eveapi\Models\Killmails\KillmailDetail;
use Seat\Eveapi\Models\Killmails\KillmailVictim;

/**
 * Class Detail.
 * @package Seat\Eveapi\Jobs\Killmails
 */
class Detail extends EsiBase
{
    /**
     * @var int
     */
    private $killmail_id;

    /**
     * @var string
     */
    private $killmail_hash;

    /**
     * @var string
     */
    protected $method = 'get';

    /**
     * @var string
     */
    protected $endpoint = '/killmails/{killmail_id}/{killmail_hash}/';

    /**
     * @var int
     */
    protected $version = 'v1';

    /**
     * @var array
     */
    protected $tags = ['killmail'];

    /**
     * Detail constructor.
     *
     * @param int $killmail_id
     * @param string $killmail_hash
     */
    public function __construct(int $killmail_id, string $killmail_hash)
    {
        $this->killmail_id = $killmail_id;
        $this->killmail_hash = $killmail_hash;

        array_push($this->tags, $killmail_id);
    }

    /**
     * Execute the job.
     *
     * @return void
     * @throws \Exception
     */
    public function handle()
    {
        $detail = $this->retrieve([
            'killmail_id'   => $this->killmail_id,
            'killmail_hash' => $this->killmail_hash,
        ]);

        if ($detail->isCachedLoad()) return;

        logger()->debug('I am spawning models');

        $killmail = KillmailDetail::firstOrCreate([
            'killmail_id'     => $this->killmail_id,
        ], [
            'killmail_time'   => carbon($detail->killmail_time),
            'solar_system_id' => $detail->solar_system_id,
            'moon_id'         => property_exists($detail, 'moon_id') ? $detail->moon_id : null,
            'war_id'          => property_exists($detail, 'war_id') ? $detail->war_id : null,
        ]);

        $victim = KillmailVictim::firstOrCreate([
            'killmail_id'    => $this->killmail_id,
        ], [
            'character_id'   => property_exists($detail->victim, 'character_id') ?
                $detail->victim->character_id : null,
            'corporation_id' => property_exists($detail->victim, 'corporation_id') ?
                $detail->victim->corporation_id : null,
            'alliance_id'    => property_exists($detail->victim, 'alliance_id') ?
                $detail->victim->alliance_id : null,
            'faction_id'     => property_exists($detail->victim, 'faction_id') ?
                $detail->victim->faction_id : null,
            'damage_taken'   => $detail->victim->damage_taken,
            'ship_type_id'   => $detail->victim->ship_type_id,
            'x'              => property_exists($detail->victim, 'position') ?
                $detail->victim->position->x : null,
            'y'              => property_exists($detail->victim, 'position') ?
                $detail->victim->position->y : null,
            'z'              => property_exists($detail->victim, 'position') ?
                $detail->victim->position->z : null,
        ]);

        collect($detail->attackers)->each(function ($attacker) {

            KillmailAttacker::firstOrCreate([
                'killmail_id'     => $this->killmail_id,
                'character_id'    => property_exists($attacker, 'character_id') ?
                    $attacker->character_id : null,
                'corporation_id'  => property_exists($attacker, 'corporation_id') ?
                    $attacker->corporation_id : null,
                'alliance_id'     => property_exists($attacker, 'alliance_id') ?
                    $attacker->alliance_id : null,
                'faction_id'      => property_exists($attacker, 'faction_id') ?
                    $attacker->faction_id : null,
            ], [
                'security_status' => $attacker->security_status,
                'final_blow'      => $attacker->final_blow,
                'damage_done'     => $attacker->damage_done,
                'ship_type_id'    => property_exists($attacker, 'ship_type_id') ?
                    $attacker->ship_type_id : null,
                'weapon_type_id'  => property_exists($attacker, 'weapon_type_id') ?
                    $attacker->weapon_type_id : null,
            ]);
        });

        if (property_exists($detail->victim, 'items')) {

            collect($detail->victim->items)->each(function ($item) use ($victim) {

                $pivot_attributes = [
                    'flag'      => $item->flag,
                    'singleton' => $item->singleton,
                ];

                if (property_exists($item, 'quantity_destroyed'))
                    $pivot_attributes['quantity_destroyed'] = $item->quantity_destroyed;

                if (property_exists($item, 'quantity_dropped'))
                    $pivot_attributes['quantity_dropped'] = $item->quantity_dropped;

                $victim->items()->attach($item->item_type_id, $pivot_attributes);
            });
        }

        event(sprintf('eloquent.updated: %s', KillmailDetail::class), $killmail);
    }
}
