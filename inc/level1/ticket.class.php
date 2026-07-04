<?php

namespace GlpiPlugin\Aisuite\Level1;

use CommonDBTM;
use Ticket as GlpiTicket;
use ITILFollowup;

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

/* Technical: Handles Hooks for Ticket/ITILFollowup Objects within GLPI lifecycle */
class Ticket extends CommonDBTM {

   /**
    * Technical: Hook item_add (Ticket)
    * Triggered post-persistence of Ticket object in database: starts the
    * Level 1 Assistant conversation (round 1).
    *
    * @param GlpiTicket $ticket The GLPI Ticket object
    * @return void
    */
   static function hookItemAdd(GlpiTicket $ticket) {
      /* Technical: Filter out templates and deleted items to avoid unnecessary processing */
      if ($ticket->isTemplate() || $ticket->isDeleted()) {
         return;
      }

      $assistant = new Assistant();
      $assistant->handleTicketCreation($ticket);
   }

   /**
    * Technical: Hook item_add (ITILFollowup)
    * Triggered when a new followup is added to any ITIL item. Only reacts to
    * public followups added to a Ticket that has a pending Level 1 Assistant
    * conversation; ignores the assistant's own followups (posted with
    * users_id = 0) to avoid self-triggering loops.
    *
    * The AI only ever responds to end users on the Helpdesk (self-service)
    * interface. If someone with a technical profile (Technician, Admin,
    * Super-Admin, Observer...) answers directly on a ticket the assistant was
    * still handling, the AI steps back for good on that ticket instead.
    *
    * @param ITILFollowup $followup
    * @return void
    */
   static function hookFollowupAdd(ITILFollowup $followup) {
      if (($followup->fields['itemtype'] ?? '') !== 'Ticket') {
         return;
      }

      if (!empty($followup->fields['is_private'])) {
         return;
      }

      // A users_id of 0 means this followup was posted by the assistant
      // itself (see Assistant::postSolutionFollowup() and friends): never
      // react to our own messages.
      if (empty($followup->fields['users_id'])) {
         return;
      }

      $ticketId = (int)($followup->fields['items_id'] ?? 0);
      $content  = (string)($followup->fields['content'] ?? '');

      if ($ticketId <= 0 || $content === '') {
         return;
      }

      $assistant = new Assistant();

      if (\Session::getCurrentInterface() !== 'helpdesk') {
         $assistant->markTechnicianTakeover($ticketId);
         return;
      }

      $assistant->handleFollowupReply($ticketId, $content);
   }

   /**
    * Technical: Hook item_get_datas (NotificationTargetTicket).
    * The assistant's own followups are always posted with users_id = 0 (see
    * Assistant::postSolutionFollowup() and friends) so it never impersonates
    * whichever human happens to be logged in, and — just as importantly — so
    * it never needs a real, loggable GLPI user account for security reasons.
    *
    * The downside: GLPI only merges the whole "##followup.author.*##" family
    * of tags (name, id, email, phone, ...) when it can load a real User for
    * followup['users_id'] (see NotificationTargetCommonITILObject::
    * getDataForObject()). Since users_id = 0 matches no user, that merge is
    * skipped entirely for the assistant's followups, so those tags are never
    * added to the template data at all — not merely empty. A notification
    * template referencing any of them (e.g. "##followup.author.name##")
    * therefore shows the raw, unreplaced tag instead of blank text. This hook
    * fixes that by unconditionally defining every tag in that family for the
    * assistant's followups, without creating any account anywhere.
    *
    * Detection relies on the assistant's own message signature (every one of
    * its followups starts with the "🤖 Assistant IA" marker) rather than on
    * users_id === 0 alone, since a genuinely anonymous followup coming from
    * another source (e.g. a mail collector) would also resolve to
    * users_id = 0 and must not be relabelled.
    *
    * @param \NotificationTargetTicket $target
    * @return \NotificationTargetTicket
    */
   static function hookNotificationData($target) {
      if (!isset($target->data['followups']) || !is_array($target->data['followups'])) {
         return $target;
      }

      $marker     = "\xF0\x9F\xA4\x96 " . __('Assistant IA', 'aisuite'); // "🤖 Assistant IA"
      $authorName = __('Assistant IA', 'aisuite');

      // Every "##followup.author.*##" tag GLPI's core knows how to emit
      // (see NotificationTargetCommonITILObject's TAG_LANGUAGE descriptions),
      // always defined so none of them can ever show up unreplaced in a
      // template, whichever one the admin happens to use.
      $authorTags = [
         '##followup.author##'              => $authorName,
         '##followup.author.itemtype##'     => '',
         '##followup.author.actortype##'    => '',
         '##followup.author.id##'           => '',
         '##followup.author.name##'         => $authorName,
         '##followup.author.location##'     => '',
         '##followup.author.usertitle##'    => '',
         '##followup.author.usercategory##' => '',
         '##followup.author.email##'        => '',
         '##followup.author.mobile##'       => '',
         '##followup.author.phone##'        => '',
         '##followup.author.phone2##'       => '',
         '##followup.author.fax##'          => '',
         '##followup.author.website##'      => '',
         '##followup.author.address##'      => '',
         '##followup.author.postcode##'     => '',
         '##followup.author.town##'         => '',
         '##followup.author.state##'        => '',
         '##followup.author.country##'      => '',
         '##followup.author.comments##'     => '',
      ];

      foreach ($target->data['followups'] as &$followup) {
         if (!is_array($followup)) {
            continue;
         }
         $description = (string)($followup['##followup.description##'] ?? '');
         if (mb_strpos($description, $marker) !== false) {
            foreach ($authorTags as $tag => $value) {
               $followup[$tag] = $value;
            }
         }
      }
      unset($followup);

      return $target;
   }
}
