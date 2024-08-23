<?php

namespace CommonGateway\ZgwVrijBRPRequestBundle\ActionHandler;

use CommonGateway\CoreBundle\ActionHandler\ActionHandlerInterface;
use CommonGateway\ZgwVrijBRPRequestBundle\Service\RequestService;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * A handler for checking if there are Cases we need to create a Request for.
 *
 * @author Wilco Louwerse <wilco@conduction.nl>
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */
class CasesHandler implements ActionHandlerInterface
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
                'beforeTimeModifier' => [
                    'type'        => 'string',
                    'description' => 'The string passed to new DateTime())->modify() that is used as filter on _self.dateCreated when searching for cases.',
                    'example'     => '-10 minutes',
                    'required'    => true,
                ],
                'schema' => [
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
        return $this->requestService->checkCasesHandler($data, $configuration);

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
    }


}//end class
