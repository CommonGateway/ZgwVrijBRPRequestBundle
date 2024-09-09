<?php

namespace CommonGateway\ZgwVrijBRPRequestBundle\Service;

use App\Event\ActionEvent;
use CommonGateway\CoreBundle\Service\CacheService;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * A service for triggering actions that create api requests to VrijBRP for ZGW cases with specific 'e-dienst' case types.
 *
 * @author Wilco Louwerse <wilco@conduction.nl>
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @category Service
 */
class ZgwToVrijbrpService
{

    /**
     * @var SymfonyStyle
     */
    private SymfonyStyle $style;


    /**
     * @param LoggerInterface          $pluginLogger    The logger interface.
     * @param CacheService             $cacheService    The cache service.
     * @param EventDispatcherInterface $eventDispatcher The event dispatcher.
     * @param EntityManagerInterface   $entityManager   The Entity Manager.
     */
    public function __construct(
        private readonly LoggerInterface $pluginLogger,
        private readonly CacheService $cacheService,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly EntityManagerInterface $entityManager
    ) {

    }//end __construct()


    /**
     * Set symfony style for command output during cronjob:command.
     *
     * @param SymfonyStyle $style Symfony style.
     *
     * @return void
     */
    public function setStyle(SymfonyStyle $style): void
    {
        $this->style = $style;

    }//end setStyle()


    /**
     * Checks if there are Cases we need to send an API Request for to VrijBRP.
     *
     * @param array $data          The data in the request.
     * @param array $configuration The configuration for this handler.
     *
     * @return array The request data.
     */
    public function checkCasesToVrijBRPHandler(array $data, array $configuration): array
    {
        // Create the DateTime object for 10 minutes ago.
        $beforeDateTime = (new DateTime())->modify(modifier: $configuration['beforeTimeModifier']);

        /*
         * Todo: FirstRegistration might work differently? documents.0.zaak.zaaktype.identificatie in ['B333', 'B334']
         */

        // Get caseTypes to search for. (string configuration fields are still configurable in the Gateway UI, array not).
        $caseTypes = explode(separator: ',', string: $configuration['caseTypes']);

        // Search all cases we should send api requests for to VrijBRP.
        $result = $this->cacheService->searchObjects(
            [
                '_self.synchronizations'          => 'IS NULL',
                'embedded.zaaktype.identificatie' => $caseTypes,
                '_self.dateCreated'               => ['before' => $beforeDateTime->format(format: 'Y-m-d H:i:s')],
            ],
            [$configuration['schema']]
        );

        if (isset($this->style) === true) {
            $this->style->section('checkCasesToVrijBRPHandler');
            $this->style->writeln('Found '.count($result['results']).' Cases to handle and send api requests for to VrijBRP.');
        }

        // Loop through results and start throwing events that will send api requests to VrijBRP.
        foreach ($result['results'] as $zaak) {
            if (isset($this->style) === true) {
                $this->style->writeln('Handling case with id: '.$zaak['_id'].' & case type: '.$zaak['embedded']['zaaktype']['identificatie']);
            }

            // Let's make sure we send the data of this object with the thrown event in the exact same way we did before
            // without embedded for example (in other Bundles like ZdsToZGWBundle)
            $object         = $this->entityManager->getRepository('App:ObjectEntity')->find($zaak['_id']);
            $data['object'] = $object->toArray();

            // Throw (async) event for mapping and sending information to VrijBRP soap API.
            $event = new ActionEvent('commongateway.action.event', $data, 'vrijbrp.zaak.created');
            $this->eventDispatcher->dispatch($event, 'commongateway.action.event');

            if (empty($zaak['embedded']['zaakinformatieobjecten']) === false) {
                foreach ($zaak['embedded']['zaakinformatieobjecten'] as $zaakInformatieObject) {
                    if (isset($this->style) === true) {
                        $this->style->writeln('Handling document '.$zaakInformatieObject['titel'].' for case with id: '.$zaak['_id'].' & case type: '.$zaak['embedded']['zaaktype']['identificatie']);
                    }

                    // Let's make sure we send the data of this object with the thrown event in the exact same way we did before
                    // without embedded for example (in other Bundles like ZdsToZGWBundle)
                    $object              = $this->entityManager->getRepository('App:ObjectEntity')->find($zaakInformatieObject['_id']);
                    $data['documents'][] = $object->toArray();
                }

                // Throw (async) event for mapping and sending information to VrijBRP Dossier API.
                $event = new ActionEvent('commongateway.action.event', $data, 'vrijbrp.document.created');
                $this->eventDispatcher->dispatch($event, 'commongateway.action.event');
            }
        }//end foreach

        return $data;

    }//end checkCasesToVrijBRPHandler()


}//end class
