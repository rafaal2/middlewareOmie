document.getElementById('importarBtn').addEventListener('click', function() {
    const resultDiv = document.getElementById('result');
    resultDiv.innerHTML = 'Importação iniciada...';

    fetch('http://localhost/middlewareOmie/src/Api/importar.php?acao=importar', {
        method: 'POST'
    })
        .then(response => response.json())
        .then(data => {
            resultDiv.innerHTML = JSON.stringify(data, null, 2);
        })
        .catch(error => {
            resultDiv.innerHTML = 'Erro: ' + error;
        });
});

document.getElementById('apagarBtn').addEventListener('click', function() {
    const resultDiv = document.getElementById('result');
    resultDiv.innerHTML = 'Apagando produtos...';

    fetch('http://localhost/middlewareOmie/src/Api/importar.php?acao=apagar', {
        method: 'POST'
    })
        .then(response => response.json())
        .then(data => {
            resultDiv.innerHTML = JSON.stringify(data, null, 2);
        })
        .catch(error => {
            resultDiv.innerHTML = 'Erro: ' + error;
        });
});
