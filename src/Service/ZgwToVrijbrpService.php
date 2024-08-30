<?php

namespace CommonGateway\ZgwVrijBRPRequestBundle\Service;

use App\Event\ActionEvent;
use CommonGateway\CoreBundle\Service\CacheService;
use DateTime;
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
     */
    public function __construct(
        private readonly LoggerInterface $pluginLogger,
        private readonly CacheService $cacheService,
        private readonly EventDispatcherInterface $eventDispatcher,
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
     * Checks if there are Cases we need to create a Request for. TODO: rename function and rewrite docblock
     *
     * @param array $data          The data in the request.
     * @param array $configuration The configuration for this handler.
     *
     * @return array The request data.
     */
    public function checkCasesHandler(array $data, array $configuration): array
    {
        // Create the DateTime object for 10 minutes ago.
        $beforeDateTime = (new DateTime())->modify(modifier: $configuration['beforeTimeModifier']);
        
        /**
         * Todo: Focus on 'Naamgebruik', = 'B0348'
         * Todo: Check / get cases for zaaktype identificatie in ['B0328', 'B0255', 'B0348', 'B1425', 'B0237', 'B0337', 'B0360', 'B0366']
         * (first 4 are from NaamgebruikVrijBRPBundle, last 4 are from GeboorteVrijBRPBundle)
         * Todo: FirstRegistration might work differently? documents.0.zaak.zaaktype.identificatie in ['B333', 'B334']
         */
        
        // Search all cases we should create Requests for.
        $result = $this->cacheService->searchObjects(
            [
                '_self.synchronizations'          => 'IS NULL',
                'embedded.zaaktype.identificatie' => 'B0348', // in ['B0328', 'B0255', 'B0348', 'B1425', 'B0237', 'B0337', 'B0360', 'B0366']
                '_self.dateCreated'               => ['before' => $beforeDateTime->format(format: 'Y-m-d H:i:s')],
            ],
            [$configuration['schema']]
        );

        if (isset($this->style) === true) {
            $this->style->section('checkCasesHandler');
            $this->style->writeln('Found '.count($result['results']).' Cases to create Requests for.');
        }

        // Loop through results and start creating Requests.
        foreach ($result['results'] as $zaak) {
            /**
             * Todo: throw event for "vrijbrp.zaak.created" for other 9 e-diensten. With ['object' => $zaak]
             * Are we sure a sync object is created after throwing this event?
             */
            
            // Throw (async) event for creating a Request for this Case.
            $event = new ActionEvent('commongateway.action.event', ['body' => $zaak], 'vrijbrp.caseToRequest.sync');
            $this->eventDispatcher->dispatch($event, 'commongateway.action.event');
        }

        return $data;

    }//end checkCasesHandler()


}//end class
