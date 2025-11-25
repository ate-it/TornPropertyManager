using Avalonia.Controls;
using Avalonia.Interactivity;
using System.Threading.Tasks;

namespace TornPropertyManager;

public partial class ApiKeyWindow : Window
{
    private readonly SettingsService _settingsService;
    private readonly TornApiService _apiService;

    public ApiKeyWindow(SettingsService settingsService, TornApiService apiService)
    {
        InitializeComponent();
        _settingsService = settingsService;
        _apiService = apiService;

        // Pre-fill if we already have a saved key
        var settings = _settingsService.Load();
        if (!string.IsNullOrWhiteSpace(settings.ApiKey))
        {
            ApiKeyTextBox.Text = settings.ApiKey;
        }
    }

    private async void SaveButton_OnClick(object? sender, RoutedEventArgs e)
    {
        var key = ApiKeyTextBox.Text?.Trim();

        if (string.IsNullOrEmpty(key))
        {
            StatusText.Text = "Please enter an API key.";
            return;
        }

        StatusText.Text = "Validating key...";
        SaveButton.IsEnabled = false;

        var isValid = await _apiService.ValidateKeyAsync(key);

        if (!isValid)
        {
            StatusText.Text = "API key is invalid or could not be validated. Please check and try again.";
            SaveButton.IsEnabled = true;
            return;
        }

        // Save key
        var settings = _settingsService.Load();
        settings.ApiKey = key;
        _settingsService.Save(settings);

        // Open main window and close this one
        var mainWindow = new MainWindow(_settingsService, _apiService);
        mainWindow.Show();

        Close();
    }
}
