<?php

namespace GlpiPlugin\Aisuite\SmartSorter;

use CommonDBTM;
use Ticket as GlpiTicket;

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

/* Technical: Handles Hooks for Ticket Object within GLPI lifecycle */
class Ticket extends CommonDBTM {

   /**
    * Technical: Hook item_add
    * Triggered post-persistence of Ticket object in database
    *
    * @param GlpiTicket $ticket The GLPI Ticket object
    * @return void
    */
   static function hookItemAdd(GlpiTicket $ticket) {
      /* Technical: Filter out templates and deleted items to avoid unnecessary processing */
      if ($ticket->isTemplate() || $ticket->isDeleted()) {
         return;
      }

      /* Technical: Execute Sorter logic in synchronous mode for immediate classification */
      $sorter = new Sorter();
      $sorter->handleTicketCreation($ticket);
   }
}
