<?php

namespace CommonGateway\ZgwVrijBRPRequestBundle\ActionHandler;

use CommonGateway\CoreBundle\ActionHandler\ActionHandlerInterface;
use CommonGateway\ZgwVrijBRPRequestBundle\Service\RequestService;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * A handler for mapping a ZGW case to a Request.
 *
 * @author Wilco Louwerse <wilco@conduction.nl>
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */
class RequestHandler implements ActionHandlerInterface
{


    /**
     * The constructor
     *
     * @param RequestService $requestService The request service
     */
    public function __construct(
        private readonly RequestService $requestService
    ) {

    }//end __construct()


    /**
     * Returns the required configuration as a https://json-schema.org array.
     *
     * @return array The configuration that this  action should comply to
     */
    public function getConfiguration(): array
    {
        return [
            '$id'         => 'https://example.com/ActionHandler/PetStoreHandler.ActionHandler.json',
            '$schema'     => 'https://docs.commongateway.nl/schemas/ActionHandler.schema.json',
            'title'       => 'PetStore ActionHandler',
            'description' => 'This handler returns a welcoming string',
            'required'    => [],
            'properties'  => [
                'source'  => [
                    'type'        => 'string',
                    'description' => 'The source where the requests should be created.',
                    'example'     => 'https://vrijbrp.nl/sources/vrijbrp.requestInbox.source.json',
                    'required'    => true,
                ],
                'mapping' => [
                    'type'        => 'string',
                    'description' => 'The mapping to translate case to request',
                    'example'     => 'https://commongateway.nl/mapping/ZaakToRequest.mapping.json',
                    'required'    => true,
                ],
                'schema'  => [
                    'type'        => 'string',
                    'description' => 'The schema for a case',
                    'example'     => 'https://vng.opencatalogi.nl/schemas/zrc.zaak.schema.json',
                    'required'    => true,
                ],
            ],
        ];

    }//end getConfiguration()


    /**
     * This function runs the service.
     *
     * @param array $data          The data from the call
     * @param array $configuration The configuration of the action
     *
     * @return array
     *
     * @SuppressWarnings("unused") Handlers are strict implementations
     */
    public function run(array $data, array $configuration): array
    {
        return $this->requestService->createRequestHandler($data, $configuration);

    }//end run()


    /**
     * Set symfony style for command output during cronjob:command.
     *
     * @param SymfonyStyle $style Symfony style.
     *
     * @return void
     */
    public function setStyle(SymfonyStyle $style): void
    {
        $this->requestService->setStyle($style);

    }//end setStyle()


}//end class
